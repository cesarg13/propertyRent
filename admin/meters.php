<?php
// =============================================================
//  PropertyRent — Admin: Medidores y Servicios Públicos
//  - Registrar lecturas de medidor (agua, gas, energía)
//  - Calcular valor a cobrar por consumo
//  - Registrar factura total del inmueble y distribuir por unidad
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$user   = dpr_current_user();
$msg = $type = '';
$tab    = $_GET['tab'] ?? 'lecturas'; // lecturas | factura_global

// ---------------------------------------------------------------
// POST: registrar lectura / guardar servicio
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // Registrar lectura individual de unidad
    if ($act === 'save_reading') {
        $unit_id    = (int)$_POST['unit_id'];
        $lease_id   = (int)($_POST['lease_id'] ?? 0) ?: null;
        $tipo       = $_POST['tipo']            ?? 'agua';
        $periodo    = $_POST['periodo']         ?? date('Y-m');
        $lec_ant    = (float)$_POST['lectura_anterior'];
        $lec_act    = (float)$_POST['lectura_actual'];
        $tarifa     = (float)$_POST['tarifa'];
        $cargo_fijo = (float)($_POST['cargo_fijo'] ?? 0);
        $incluido   = isset($_POST['incluido']) ? 1 : 0;

        // Si la unidad no tiene contrato, el responsable es el propietario
        $responsable_tipo = $lease_id ? 'inquilino' : 'propietario';

        $calc  = dpr_calcular_consumo($lec_ant, $lec_act, $tarifa, $cargo_fijo);
        $valor = $incluido ? 0 : $calc['valor'];

        // Guardar lectura en tabla meter_readings (definida abajo)
        db_execute($mysqli,
            "INSERT INTO meter_readings (unit_id, tipo, periodo, lectura_anterior, lectura_actual, consumo, tarifa, cargo_fijo, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE lectura_actual=VALUES(lectura_actual), consumo=VALUES(consumo), tarifa=VALUES(tarifa)",
            'issdddddi', [$unit_id,$tipo,$periodo,$lec_ant,$lec_act,$calc['consumo'],$tarifa,$cargo_fijo,$user['id']]);

        // El id de la lectura: si fue INSERT nuevo, insert_id lo trae; si fue
        // ON DUPLICATE KEY UPDATE sobre una fila existente, insert_id puede
        // venir en 0 — en ese caso lo recuperamos con un SELECT explícito
        // (necesitamos el id real para enlazar meter_reading_id en obligations).
        $reading_id = $mysqli->insert_id;
        if (!$reading_id) {
            $existing = db_query($mysqli,
                "SELECT id FROM meter_readings WHERE unit_id=? AND tipo=? AND periodo=?",
                'iss', [$unit_id, $tipo, $periodo]);
            $reading_id = (int)($existing[0]['id'] ?? 0);
        }

        // Guardar/actualizar en public_services (lease_id puede ser NULL si la
        // unidad la ocupa el propietario y no tiene contrato de arriendo)
        db_execute($mysqli,
            "INSERT INTO public_services (unit_id,lease_id,responsable_tipo,tipo,periodo,valor,incluido,estado)
             VALUES (?,?,?,?,?,?,?,'pendiente')
             ON DUPLICATE KEY UPDATE valor=VALUES(valor), incluido=VALUES(incluido), responsable_tipo=VALUES(responsable_tipo)",
            'iisssdi', [$unit_id,$lease_id,$responsable_tipo,$tipo,$periodo,$valor,$incluido]);

        dpr_audit_log($mysqli, $user['id'], 'INSERT', 'meter_readings', null,
            "Lectura $tipo unidad_id=$unit_id periodo=$periodo consumo={$calc['consumo']}");
        $msg = "Lectura registrada. Consumo: {$calc['consumo']} unidades · Valor: " . fmt_money($calc['valor']); $type = 'success';

        // Puente al ledger de pagos: solo si la unidad tiene inquilino con
        // contrato activo. Si la ocupa el propietario (lease_id NULL), el
        // cargo queda solo en public_services, igual que antes — no hay
        // "deuda de inquilino" que registrar en obligations.
        if ($lease_id) {
            $fecha_limite_obl = dpr_fecha_vencimiento(
                (int)(db_query($mysqli, "SELECT dia_vencimiento FROM leases WHERE id=?", 'i', [$lease_id])[0]['dia_vencimiento'] ?? 5),
                $periodo
            );
            $rl = dpr_upsert_obligacion_medidor($mysqli, $lease_id, $tipo, $periodo, $valor, $fecha_limite_obl, $reading_id, $user['id']);
            if (!empty($rl['aviso'])) {
                $msg .= ' ' . $rl['aviso'];
                $type = 'warn';
            }
        }
    }

    // Registrar factura global del inmueble (agua/energía de todo el edificio)
    // Flujo real: 1) ya se cobró por medidor a las unidades que lo tienen,
    // 2) esto reparte el resto SOLO entre las unidades sin medidor de ese
    // tipo+periodo. Por eso se excluyen las que ya tienen meter_readings.
    if ($act === 'save_global') {
        $property_id = (int)$_POST['property_id'];
        $tipo        = $_POST['tipo']      ?? 'energia';
        $periodo     = $_POST['periodo']   ?? date('Y-m');
        $valor_total = (float)$_POST['valor_total'];
        $distribucion = $_POST['distribucion'] ?? 'igual'; // igual | proporcional

        // Registrar el gasto del inmueble
        db_execute($mysqli,
            "INSERT INTO expenses (property_id, tipo, descripcion, valor, fecha, registrado_por)
             VALUES (?,?,?,?,?,?)",
            'issdsi', [$property_id, 'administracion',
                "Factura $tipo período $periodo", $valor_total, date('Y-m-d'), $user['id']]);

        // Unidades ocupadas (inquilino o propietario) que NO tengan ya una
        // lectura de medidor para este tipo+periodo — esas se cobran aparte
        // por save_reading y no deben recibir un cargo duplicado aquí.
        $unidades = db_query($mysqli,
            "SELECT u.id AS unit_id, l.id AS lease_id, u.area_m2,
                    IF(l.id IS NULL, 'propietario', 'inquilino') AS responsable_tipo
             FROM units u
             LEFT JOIN leases l ON l.unit_id = u.id AND l.estado = 'activo'
             WHERE u.property_id = ? AND u.estado = 'ocupada'
               AND u.ocupante_tipo IN ('inquilino','propietario')
               AND NOT EXISTS (
                   SELECT 1 FROM meter_readings mr
                   WHERE mr.unit_id = u.id AND mr.tipo = ? AND mr.periodo = ?
               )",
            'iss', [$property_id, $tipo, $periodo]);

        if ($unidades) {
            $valores_por_unidad = []; // unit_id => valor asignado, para el puente al ledger

            if ($distribucion === 'proporcional') {
                $area_total = array_sum(array_column($unidades, 'area_m2')) ?: count($unidades);
                foreach ($unidades as $un) {
                    $proporcion = ($un['area_m2'] ?: ($area_total/count($unidades))) / $area_total;
                    $valor_un   = round($valor_total * $proporcion, 0);
                    $valores_por_unidad[$un['unit_id']] = $valor_un;
                    db_execute($mysqli,
                        "INSERT INTO public_services (unit_id,lease_id,responsable_tipo,tipo,periodo,valor,incluido,estado)
                         VALUES (?,?,?,?,?,?,0,'pendiente')
                         ON DUPLICATE KEY UPDATE valor=VALUES(valor), responsable_tipo=VALUES(responsable_tipo)",
                        'iisssd', [$un['unit_id'],$un['lease_id'],$un['responsable_tipo'],$tipo,$periodo,$valor_un]);
                }
            } else {
                // Partes iguales — el criterio por defecto para repartir lo
                // que falta entre las unidades sin medidor (el admin ajusta
                // valores puntuales después en Pagos si alguna no aplica igual).
                $valor_un = round($valor_total / count($unidades), 0);
                foreach ($unidades as $un) {
                    $valores_por_unidad[$un['unit_id']] = $valor_un;
                    db_execute($mysqli,
                        "INSERT INTO public_services (unit_id,lease_id,responsable_tipo,tipo,periodo,valor,incluido,estado)
                         VALUES (?,?,?,?,?,?,0,'pendiente')
                         ON DUPLICATE KEY UPDATE valor=VALUES(valor), responsable_tipo=VALUES(responsable_tipo)",
                        'iisssd', [$un['unit_id'],$un['lease_id'],$un['responsable_tipo'],$tipo,$periodo,$valor_un]);
                }
            }

            // Puente al ledger: una obligación por cada unidad CON lease activo.
            // Las de propietario (lease_id NULL) quedan solo en public_services,
            // igual que en save_reading — no hay "deuda de inquilino" que crear.
            $avisos = [];
            foreach ($unidades as $un) {
                if (!$un['lease_id']) continue;
                $valor_un = $valores_por_unidad[$un['unit_id']];
                $fecha_limite_obl = dpr_fecha_vencimiento(
                    (int)(db_query($mysqli, "SELECT dia_vencimiento FROM leases WHERE id=?", 'i', [$un['lease_id']])[0]['dia_vencimiento'] ?? 5),
                    $periodo
                );
                $rl = dpr_upsert_obligacion_medidor($mysqli, (int)$un['lease_id'], $tipo, $periodo, $valor_un, $fecha_limite_obl, null, $user['id']);
                if (!empty($rl['aviso'])) $avisos[] = $rl['aviso'];
            }

            $msg = "Factura global registrada y distribuida entre " . count($unidades) . " unidades sin medidor."; $type = 'success';
            if ($avisos) {
                $msg .= ' Aviso: ' . implode(' ', array_unique($avisos));
                $type = 'warn';
            }
        } else {
            $msg = 'No hay unidades activas sin medidor para distribuir esta factura (todas ya tienen lectura registrada para ese periodo, o no hay unidades ocupadas).'; $type = 'warn';
        }
    }

    // Marcar servicio como pagado
    if ($act === 'mark_paid') {
        $sid = (int)$_POST['service_id'];
        db_execute($mysqli, "UPDATE public_services SET estado='pagado' WHERE id=?", 'i', [$sid]);
        dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'public_services', $sid, 'Marcó servicio como pagado');
        $msg = 'Servicio marcado como pagado.'; $type = 'success';
    }

    // Guardar consolidado de factura: distribuye el total de una factura
    // (medidor general del inmueble) entre todas las unidades — con y sin
    // medidor individual — según el % de consumo de cada una, con ajuste
    // manual por unidad. Es el paso final: actualiza directamente lecturas
    // (las que tienen medidor) y obligaciones (todas, con lease activo).
    if ($act === 'guardar_consolidado') {
        $tipo         = $_POST['tipo']     ?? 'energia';
        $periodo      = $_POST['periodo']  ?? date('Y-m');
        $fecha_limite = trim($_POST['fecha_limite'] ?? '') ?: null;

        $unit_ids   = $_POST['unit_id']      ?? [];
        $lease_ids  = $_POST['lease_id']     ?? [];
        $con_medidor= $_POST['con_medidor']  ?? []; // '1' o '0' por fila
        $lec_ant    = $_POST['lectura_anterior'] ?? [];
        $lec_act    = $_POST['lectura_actual']   ?? [];
        $valor_final= $_POST['valor_final']  ?? [];

        $n_actualizadas = 0;
        $avisos = [];

        foreach ($unit_ids as $i => $unit_id) {
            $unit_id  = (int)$unit_id;
            $lease_id = (int)($lease_ids[$i] ?? 0) ?: null;
            $valor    = (float)str_replace(['.', '$', ','], ['', '', ''], $valor_final[$i] ?? '0');
            $tiene_medidor = ($con_medidor[$i] ?? '0') === '1';

            if ($valor < 0) continue; // fila vacía / sin completar, se omite

            $reading_id = null;

            if ($tiene_medidor) {
                $la = (float)($lec_ant[$i] ?? 0);
                $lc = (float)($lec_act[$i] ?? 0);
                // Actualizar/crear la lectura igual que save_reading, para
                // que esta pantalla y la de "Lecturas por unidad" queden
                // consistentes (misma tabla meter_readings).
                db_execute($mysqli,
                    "INSERT INTO meter_readings (unit_id, tipo, periodo, lectura_anterior, lectura_actual, consumo, tarifa, cargo_fijo, created_by)
                     VALUES (?,?,?,?,?,?,0,0,?)
                     ON DUPLICATE KEY UPDATE lectura_actual=VALUES(lectura_actual), consumo=VALUES(consumo)",
                    'issdddi', [$unit_id, $tipo, $periodo, $la, $lc, max(0, $lc - $la), $user['id']]);
                $reading_id = $mysqli->insert_id;
                if (!$reading_id) {
                    $existing = db_query($mysqli, "SELECT id FROM meter_readings WHERE unit_id=? AND tipo=? AND periodo=?", 'iss', [$unit_id, $tipo, $periodo]);
                    $reading_id = (int)($existing[0]['id'] ?? 0);
                }
            }

            // public_services: mismo registro informativo que ya usan
            // services.php / tenant/services.php, independiente de si hay
            // lease (responsable_tipo cambia según ocupante).
            $responsable_tipo = $lease_id ? 'inquilino' : 'propietario';
            db_execute($mysqli,
                "INSERT INTO public_services (unit_id,lease_id,responsable_tipo,tipo,periodo,valor,incluido,estado)
                 VALUES (?,?,?,?,?,?,0,'pendiente')
                 ON DUPLICATE KEY UPDATE valor=VALUES(valor), responsable_tipo=VALUES(responsable_tipo)",
                'iisssd', [$unit_id, $lease_id, $responsable_tipo, $tipo, $periodo, $valor]);

            // Ledger: solo si la unidad tiene inquilino con contrato activo
            // (igual regla que en save_reading/save_global — propietario sin
            // contrato queda solo en public_services).
            if ($lease_id) {
                $rl = dpr_upsert_obligacion_medidor($mysqli, $lease_id, $tipo, $periodo, $valor, $fecha_limite, $reading_id, $user['id']);
                if (!empty($rl['aviso'])) $avisos[] = "Unidad $unit_id: " . $rl['aviso'];
            }

            $n_actualizadas++;
        }

        dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'obligations', null,
            "Guardó consolidado de factura $tipo periodo=$periodo, $n_actualizadas unidades actualizadas");

        $msg = "Consolidado guardado: $n_actualizadas unidades actualizadas."; $type = 'success';
        if ($avisos) { $msg .= ' ' . implode(' ', array_unique($avisos)); $type = 'warn'; }
    }
}

// ---------------------------------------------------------------
// Datos para la vista
// ---------------------------------------------------------------
$f_periodo  = $_GET['periodo'] ?? date('Y-m');
$f_prop     = (int)($_GET['property_id'] ?? 0);

$all_props  = db_query($mysqli, "SELECT id, nombre FROM properties WHERE estado='activo' ORDER BY nombre");

// Unidades ocupadas para lecturas: inquilino con contrato activo,
// O propietario sin contrato (ocupante_tipo='propietario')
$units_active = db_query($mysqli,
    "SELECT u.id, u.nombre AS unidad, p.nombre AS inmueble, l.id AS lease_id,
            COALESCE(CONCAT(us.nombre,' ',us.apellido), 'Propietario') AS inquilino
     FROM units u
     JOIN properties p ON p.id = u.property_id
     LEFT JOIN leases l ON l.unit_id = u.id AND l.estado = 'activo'
     LEFT JOIN users us ON us.id = l.tenant_id
     WHERE u.estado = 'ocupada'
       AND u.ocupante_tipo IN ('inquilino','propietario')" .
     ($f_prop ? " AND p.id = $f_prop" : '') .
    " ORDER BY p.nombre, u.nombre");

// Última lectura por unidad/tipo
$last_readings = db_query($mysqli,
    "SELECT unit_id, tipo, MAX(periodo) AS ultimo_periodo, lectura_actual
     FROM meter_readings GROUP BY unit_id, tipo");
$lec_map = [];
foreach ($last_readings as $lr) {
    $lec_map[$lr['unit_id']][$lr['tipo']] = $lr;
}

// Servicios del período filtrado (incluye unidades sin contrato, ocupadas por el propietario)
$where_s = "ps.periodo = '$f_periodo'" . ($f_prop ? " AND p.id = $f_prop" : '');
$services = db_query($mysqli,
    "SELECT ps.*, u.nombre AS unidad, p.nombre AS inmueble,
            COALESCE(CONCAT(us.nombre,' ',us.apellido), 'Propietario') AS inquilino
     FROM public_services ps
     JOIN units u ON u.id = ps.unit_id
     JOIN properties p ON p.id = u.property_id
     LEFT JOIN leases l ON l.id = ps.lease_id
     LEFT JOIN users us ON us.id = l.tenant_id
     WHERE $where_s
     ORDER BY p.nombre, u.nombre, ps.tipo");

// ---------------------------------------------------------------
// CONSOLIDADO DE FACTURA — datos propios (filtros con prefijo c_)
// ---------------------------------------------------------------
$c_prop    = (int)($_GET['c_property_id'] ?? 0);
$c_tipo    = $_GET['c_tipo']    ?? 'energia';
$c_periodo = $_GET['c_periodo'] ?? date('Y-m');

$consolidado_unidades = [];
$consolidado_consumo_medidores = 0; // suma de consumo de unidades CON medidor

if ($c_prop && $tab === 'consolidado') {
    $rows = db_query($mysqli,
        "SELECT u.id AS unit_id, u.nombre AS unidad, l.id AS lease_id,
                COALESCE(CONCAT(us.nombre,' ',us.apellido), 'Propietario') AS inquilino,
                mr.lectura_anterior, mr.lectura_actual, mr.consumo
         FROM units u
         LEFT JOIN leases l ON l.unit_id = u.id AND l.estado = 'activo'
         LEFT JOIN users us ON us.id = l.tenant_id
         LEFT JOIN meter_readings mr ON mr.unit_id = u.id AND mr.tipo = ? AND mr.periodo = ?
         WHERE u.property_id = ? AND u.estado = 'ocupada' AND u.ocupante_tipo IN ('inquilino','propietario')
         ORDER BY (mr.id IS NULL) ASC, u.nombre ASC",
        'sii', [$c_tipo, $c_periodo, $c_prop]);

    foreach ($rows as $r) {
        $tiene_medidor = $r['lectura_actual'] !== null;
        if ($tiene_medidor) $consolidado_consumo_medidores += (float)$r['consumo'];
        $consolidado_unidades[] = [
            'unit_id'        => (int)$r['unit_id'],
            'unidad'         => $r['unidad'],
            'lease_id'       => $r['lease_id'] ? (int)$r['lease_id'] : null,
            'inquilino'      => $r['inquilino'],
            'tiene_medidor'  => $tiene_medidor,
            'lectura_anterior' => $tiene_medidor ? (float)$r['lectura_anterior'] : null,
            'lectura_actual'   => $tiene_medidor ? (float)$r['lectura_actual'] : null,
            'consumo'        => $tiene_medidor ? (float)$r['consumo'] : null,
        ];
    }
}

$page_title  = 'Medidores y Servicios';
$active_menu = 'meters';
require_once __DIR__ . '/../includes/header.php';
if ($msg): ?><div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Medidores y Servicios Públicos</div>
    <div class="dpr-page-sub">Lecturas individuales y facturación global del inmueble</div>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:4px;margin-bottom:18px;border-bottom:1px solid var(--border);padding-bottom:0">
  <?php foreach (['lecturas'=>'Lecturas por unidad','factura_global'=>'Factura global inmueble','consolidado'=>'Consolidado factura','historial'=>'Historial'] as $t=>$label): ?>
  <a href="?tab=<?= $t ?>&periodo=<?= h($f_periodo) ?>&property_id=<?= $f_prop ?>"
     style="padding:9px 16px;font-size:13px;border-radius:6px 6px 0 0;text-decoration:none;
            background:<?= $tab===$t?'#fff':'transparent' ?>;
            color:<?= $tab===$t?'var(--navy)':'var(--text-muted)' ?>;
            border:<?= $tab===$t?'0.5px solid var(--border)':'none' ?>;border-bottom:none;font-weight:<?= $tab===$t?'600':'400' ?>">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'lecturas'): ?>
<!-- ===== LECTURAS POR UNIDAD ===== -->
<div class="dpr-card">
  <div class="dpr-card__title">Registrar lectura de medidor</div>
  <p class="dpr-text-muted" style="font-size:12px;margin-bottom:14px">
    Ingresa las lecturas del medidor. El sistema calcula automáticamente el consumo y el valor a cobrar al inquilino.
  </p>
  <form method="POST">
    <input type="hidden" name="action" value="save_reading">
    <div class="dpr-form-grid dpr-form-grid--3">
      <div class="dpr-form-group">
        <label class="dpr-label">Unidad / Inquilino *</label>
        <select name="unit_id" class="dpr-select" required id="unitSel" onchange="fillLease(this)">
          <option value="">Seleccionar...</option>
          <?php foreach ($units_active as $u): ?>
          <option value="<?= $u['id'] ?>" data-lease="<?= $u['lease_id'] ?>"><?= h($u['inmueble'].' / '.$u['unidad'].' — '.$u['inquilino']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="lease_id" id="leaseHidden">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Tipo de servicio *</label>
        <select name="tipo" class="dpr-select" required>
          <option value="agua">Agua (m³)</option>
          <option value="energia">Energía (kWh)</option>
          <option value="gas">Gas (m³)</option>
        </select>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Periodo *</label>
        <input type="month" name="periodo" class="dpr-input" value="<?= h($f_periodo) ?>" required>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Lectura anterior</label>
        <input type="number" name="lectura_anterior" id="lecAnt" class="dpr-input" step="0.01" min="0" value="0">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Lectura actual *</label>
        <input type="number" name="lectura_actual" class="dpr-input" step="0.01" min="0" required
          oninput="calcConsumption()">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Tarifa por unidad (COP) *</label>
        <input type="number" name="tarifa" class="dpr-input" step="1" min="0" required
          placeholder="Ej: 3200 por m³" oninput="calcConsumption()">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Cargo fijo (COP)</label>
        <input type="number" name="cargo_fijo" class="dpr-input" step="1" min="0" value="0" oninput="calcConsumption()">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Vista previa del cobro</label>
        <div class="dpr-input" id="calcPreview" style="background:#f8fafc;color:#334155">
          Completa lectura y tarifa para calcular
        </div>
      </div>
      <div class="dpr-form-group" style="align-self:end">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
          <input type="checkbox" name="incluido"> Incluido en el arriendo (no cobrar)
        </label>
      </div>
    </div>
    <div class="dpr-form-actions">
      <button type="submit" class="dpr-btn dpr-btn--primary">Guardar lectura</button>
    </div>
  </form>
</div>

<?php elseif ($tab === 'factura_global'): ?>
<!-- ===== FACTURA GLOBAL INMUEBLE ===== -->
<div class="dpr-card">
  <div class="dpr-card__title">Registrar factura global del inmueble</div>
  <p class="dpr-text-muted" style="font-size:12px;margin-bottom:14px">
    Cuando no hay medidores individuales. Registra la factura total y el sistema la distribuye entre todas las unidades activas del inmueble.
  </p>
  <form method="POST">
    <input type="hidden" name="action" value="save_global">
    <div class="dpr-form-grid">
      <div class="dpr-form-group">
        <label class="dpr-label">Inmueble *</label>
        <select name="property_id" class="dpr-select" required>
          <option value="">Seleccionar...</option>
          <?php foreach ($all_props as $p): ?>
          <option value="<?= $p['id'] ?>"><?= h($p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Tipo de servicio *</label>
        <select name="tipo" class="dpr-select" required>
          <option value="agua">Agua</option>
          <option value="energia">Energía</option>
          <option value="gas">Gas</option>
          <option value="internet">Internet</option>
          <option value="administracion">Administración</option>
          <option value="otro">Otro</option>
        </select>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Periodo *</label>
        <input type="month" name="periodo" class="dpr-input" value="<?= h($f_periodo) ?>" required>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Valor total de la factura (COP) *</label>
        <input type="number" name="valor_total" class="dpr-input" required min="0" step="1000" placeholder="Ej: 480000">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Método de distribución</label>
        <select name="distribucion" class="dpr-select">
          <option value="igual">Partes iguales entre unidades</option>
          <option value="proporcional">Proporcional al área (m²)</option>
        </select>
      </div>
    </div>
    <div class="dpr-form-actions">
      <button type="submit" class="dpr-btn dpr-btn--primary">Registrar y distribuir</button>
    </div>
  </form>
</div>

<?php elseif ($tab === 'historial'): ?>
<!-- ===== HISTORIAL ===== -->
<form method="GET" class="dpr-card" style="padding:12px 18px;margin-bottom:14px">
  <input type="hidden" name="tab" value="historial">
  <div class="dpr-form-grid dpr-form-grid--3" style="margin:0">
    <div class="dpr-form-group">
      <label class="dpr-label">Periodo</label>
      <input type="month" name="periodo" class="dpr-input" value="<?= h($f_periodo) ?>">
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Inmueble</label>
      <select name="property_id" class="dpr-select">
        <option value="">Todos</option>
        <?php foreach ($all_props as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $f_prop==$p['id']?'selected':'' ?>><?= h($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group" style="align-self:end">
      <button class="dpr-btn dpr-btn--primary" style="width:100%">Filtrar</button>
    </div>
  </div>
</form>

<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr><th>Inmueble/Unidad</th><th>Inquilino</th><th>Tipo</th><th>Periodo</th><th>Valor</th><th>Incluido</th><th>Estado</th><th>Acción</th></tr>
      </thead>
      <tbody>
        <?php if (!$services): ?>
        <tr><td colspan="8" class="dpr-text-muted" style="text-align:center;padding:24px">Sin registros para este período.</td></tr>
        <?php endif; ?>
        <?php foreach ($services as $s):
          $pill = match($s['estado']) { 'pagado'=>'ok','pendiente'=>'warn','incluido'=>'info', default=>'neutral' };
        ?>
        <tr>
          <td><?= h($s['inmueble'].' / '.$s['unidad']) ?></td>
          <td><?= h($s['inquilino']) ?></td>
          <td><?= ucfirst($s['tipo']) ?></td>
          <td><?= h($s['periodo']) ?></td>
          <td><?= fmt_money($s['valor']) ?></td>
          <td><?= $s['incluido'] ? '<span class="dpr-pill dpr-pill--info">Incluido</span>' : '—' ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst($s['estado']) ?></span></td>
          <td>
            <?php if ($s['estado'] === 'pendiente'): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action"     value="mark_paid">
              <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
              <button class="dpr-btn dpr-btn--sm dpr-btn--primary">Marcar pagado</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'consolidado'): ?>
<!-- ===== CONSOLIDADO DE FACTURA ===== -->
<div class="dpr-card">
  <div class="dpr-card__title">Datos de la factura</div>
  <p class="dpr-text-muted" style="font-size:12px;margin-bottom:14px">
    Carga el inmueble, tipo y período para ver todas las unidades (con y sin medidor individual)
    y distribuir el total de la factura entre ellas según su consumo.
  </p>
  <form method="GET">
    <input type="hidden" name="tab" value="consolidado">
    <div class="dpr-form-grid">
      <div class="dpr-form-group">
        <label class="dpr-label">Inmueble *</label>
        <select name="c_property_id" class="dpr-select" required>
          <option value="">Seleccionar...</option>
          <?php foreach ($all_props as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $c_prop === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Tipo de servicio *</label>
        <select name="c_tipo" class="dpr-select" required>
          <?php foreach (['agua'=>'Agua','energia'=>'Energía','gas'=>'Gas'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $c_tipo === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Periodo *</label>
        <input type="month" name="c_periodo" class="dpr-input" value="<?= h($c_periodo) ?>" required>
      </div>
      <div class="dpr-form-group" style="align-self:end">
        <button class="dpr-btn dpr-btn--primary" style="width:100%">Cargar unidades</button>
      </div>
    </div>
  </form>
</div>

<?php if ($c_prop && $consolidado_unidades): ?>
<div class="dpr-card">
  <div class="dpr-card__title">Medidor general del inmueble (datos de la factura)</div>
  <div class="dpr-form-grid">
    <div class="dpr-form-group">
      <label class="dpr-label">Lectura anterior (medidor general)</label>
      <input type="number" id="medidorGeneralAnt" class="dpr-input" step="any" oninput="recalcularConsolidado()">
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Lectura actual (medidor general)</label>
      <input type="number" id="medidorGeneralAct" class="dpr-input" step="any" oninput="recalcularConsolidado()">
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Consumo total (calculado)</label>
      <input type="text" id="medidorGeneralConsumo" class="dpr-input" readonly style="background:#f8fafc">
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Total a pagar de la factura (COP) *</label>
      <input type="number" id="totalFactura" class="dpr-input" min="0" step="1" oninput="recalcularConsolidado()" placeholder="Ej: 400000">
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Fecha límite de pago</label>
      <input type="date" id="fechaLimiteFactura" class="dpr-input">
    </div>
  </div>
</div>

<div class="dpr-card">
  <div class="dpr-card__title">Distribución por unidad</div>
  <form method="POST" id="consolidadoForm">
    <input type="hidden" name="action" value="guardar_consolidado">
    <input type="hidden" name="tipo" value="<?= h($c_tipo) ?>">
    <input type="hidden" name="periodo" value="<?= h($c_periodo) ?>">
    <input type="hidden" name="fecha_limite" id="fechaLimiteHidden">
    <div class="dpr-table-wrap">
      <table class="dpr-table" id="consolidadoTabla">
        <thead>
          <tr>
            <th>Unidad</th><th>Inquilino</th><th>Lect. anterior</th><th>Lect. actual</th>
            <th>Consumo m³</th><th>%</th><th>Valor según sistema</th><th>Valor final</th>
          </tr>
        </thead>
        <tbody>
          <?php
          // Separar unidades con medidor (fila individual) de las sin medidor
          // (fila combinada, remanente repartido a mano por el admin — ver
          // contexto de diseño: no hay fórmula automática entre ellas).
          $con_medidor_rows = array_filter($consolidado_unidades, fn($u) => $u['tiene_medidor']);
          $sin_medidor_rows = array_filter($consolidado_unidades, fn($u) => !$u['tiene_medidor']);
          ?>
          <?php foreach ($con_medidor_rows as $u): ?>
          <tr class="consolidado-row" data-consumo="<?= $u['consumo'] ?>" data-con-medidor="1">
            <td>
              <?= h($u['unidad']) ?>
              <input type="hidden" name="unit_id[]" value="<?= $u['unit_id'] ?>">
              <input type="hidden" name="lease_id[]" value="<?= $u['lease_id'] ?? '' ?>">
              <input type="hidden" name="con_medidor[]" value="1">
            </td>
            <td><?= h($u['inquilino']) ?><?= !$u['lease_id'] ? ' <span class="dpr-pill dpr-pill--neutral" style="font-size:10px">sin contrato</span>' : '' ?></td>
            <td><input type="number" name="lectura_anterior[]" class="dpr-input dpr-input--sm" value="<?= $u['lectura_anterior'] ?>" step="any" oninput="recalcularFila(this)"></td>
            <td><input type="number" name="lectura_actual[]" class="dpr-input dpr-input--sm" value="<?= $u['lectura_actual'] ?>" step="any" oninput="recalcularFila(this)"></td>
            <td class="consumo-cell"><?= number_format($u['consumo'], 2) ?></td>
            <td class="pct-cell">—</td>
            <td class="sistema-cell">—</td>
            <td><input type="number" name="valor_final[]" class="dpr-input dpr-input--sm valor-final-input" step="1" oninput="actualizarTotalFinal()"></td>
          </tr>
          <?php endforeach; ?>

          <?php if ($sin_medidor_rows): ?>
          <tr style="background:#fafafa">
            <td colspan="8" style="font-size:12px;color:var(--text-muted);padding:6px 10px">
              Unidades sin medidor individual — el remanente de consumo se reparte manualmente entre ellas (sin fórmula automática):
            </td>
          </tr>
          <?php foreach ($sin_medidor_rows as $u): ?>
          <tr class="consolidado-row" data-con-medidor="0">
            <td>
              <?= h($u['unidad']) ?>
              <input type="hidden" name="unit_id[]" value="<?= $u['unit_id'] ?>">
              <input type="hidden" name="lease_id[]" value="<?= $u['lease_id'] ?? '' ?>">
              <input type="hidden" name="con_medidor[]" value="0">
            </td>
            <td><?= h($u['inquilino']) ?><?= !$u['lease_id'] ? ' <span class="dpr-pill dpr-pill--neutral" style="font-size:10px">sin contrato</span>' : '' ?></td>
            <td colspan="2" class="dpr-text-muted" style="font-size:12px">Sin medidor</td>
            <td class="consumo-cell" colspan="2" style="font-size:12px;color:var(--text-muted)" id="remanenteInfo-<?= $u['unit_id'] ?>">remanente compartido</td>
            <td class="sistema-cell">—</td>
            <td><input type="number" name="valor_final[]" class="dpr-input dpr-input--sm valor-final-input" step="1" oninput="actualizarTotalFinal()"></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:600">
            <td colspan="4" style="text-align:right">Total:</td>
            <td id="totalConsumoCell">0</td>
            <td id="totalPctCell">100%</td>
            <td id="totalSistemaCell">$0</td>
            <td id="totalFinalCell" style="padding:8px;border-radius:6px">$0</td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div id="remanenteAviso" class="dpr-alert dpr-alert--info" style="font-size:12px;margin-top:10px;display:none"></div>
    <div class="dpr-form-actions">
      <button type="submit" class="dpr-btn dpr-btn--primary">Guardar consolidado</button>
    </div>
  </form>
</div>
<?php elseif ($c_prop): ?>
<div class="dpr-alert dpr-alert--info">No hay unidades ocupadas en este inmueble.</div>
<?php endif; ?>

<?php endif; ?>

<script>
function fillLease(sel) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('leaseHidden').value = opt.dataset.lease || '';
}
function calcConsumption() {
  const ant    = parseFloat(document.querySelector('[name=lectura_anterior]').value) || 0;
  const act    = parseFloat(document.querySelector('[name=lectura_actual]').value)   || 0;
  const tar    = parseFloat(document.querySelector('[name=tarifa]').value)            || 0;
  const fijo   = parseFloat(document.querySelector('[name=cargo_fijo]').value)       || 0;
  const cons   = Math.max(0, act - ant);
  const valor  = Math.round(cons * tar + fijo);
  document.getElementById('calcPreview').textContent =
    cons > 0 ? `Consumo: ${cons.toFixed(2)} unidades · Cobrar: $${valor.toLocaleString('es-CO')}` : 'Verifica las lecturas';
}

// =================================================================
// CONSOLIDADO DE FACTURA
// =================================================================
function fmtCOP(n) {
  return '$' + Math.round(n).toLocaleString('es-CO');
}

// Recalcula la lectura/consumo de UNA fila con medidor cuando el admin
// edita lectura_anterior o lectura_actual a mano en esta pantalla.
function recalcularFila(input) {
  const row = input.closest('tr');
  const ant = parseFloat(row.querySelector('[name="lectura_anterior[]"]').value) || 0;
  const act = parseFloat(row.querySelector('[name="lectura_actual[]"]').value) || 0;
  const consumo = Math.max(0, act - ant);
  row.dataset.consumo = consumo;
  row.querySelector('.consumo-cell').textContent = consumo.toFixed(2);
  recalcularConsolidado();
}

// Recalcula TODA la tabla: % de cada unidad con medidor (consumo unidad /
// consumo del medidor general), su "valor según sistema" (% × total de la
// factura), el remanente para las unidades sin medidor, y precarga el
// "valor final" si el admin todavía no lo ha tocado a mano.
function recalcularConsolidado() {
  const medAnt = parseFloat(document.getElementById('medidorGeneralAnt')?.value) || 0;
  const medAct = parseFloat(document.getElementById('medidorGeneralAct')?.value) || 0;
  const consumoGeneral = Math.max(0, medAct - medAnt);
  document.getElementById('medidorGeneralConsumo').value = consumoGeneral ? consumoGeneral.toFixed(2) : '';

  const totalFactura = parseFloat(document.getElementById('totalFactura')?.value) || 0;

  const rows = document.querySelectorAll('.consolidado-row');
  let sumaConsumoMedidores = 0;
  rows.forEach(row => {
    if (row.dataset.conMedidor === '1') {
      sumaConsumoMedidores += parseFloat(row.dataset.consumo || '0');
    }
  });

  const remanente = Math.max(0, consumoGeneral - sumaConsumoMedidores);
  const sinMedidorRows = document.querySelectorAll('.consolidado-row[data-con-medidor="0"]');

  // Aviso del remanente: visible siempre que haya unidades sin medidor,
  // para que el admin sepa cuánto debe repartir manualmente entre ellas.
  const aviso = document.getElementById('remanenteAviso');
  if (sinMedidorRows.length > 0 && consumoGeneral > 0) {
    const pctRemanente = consumoGeneral > 0 ? (remanente / consumoGeneral * 100) : 0;
    aviso.style.display = 'block';
    aviso.textContent = `Remanente sin medidor: ${remanente.toFixed(2)} m³ (${pctRemanente.toFixed(1)}% de la factura, `
      + `≈ ${fmtCOP(totalFactura * pctRemanente / 100)}) a repartir manualmente entre ${sinMedidorRows.length} unidad(es) sin medidor.`;
  } else {
    aviso.style.display = 'none';
  }

  rows.forEach(row => {
    const conMedidor = row.dataset.conMedidor === '1';
    const pctCell = row.querySelector('.pct-cell');
    const sistemaCell = row.querySelector('.sistema-cell');
    const valorFinalInput = row.querySelector('.valor-final-input');

    if (conMedidor) {
      const consumo = parseFloat(row.dataset.consumo || '0');
      const pct = consumoGeneral > 0 ? (consumo / consumoGeneral * 100) : 0;
      const valorSistema = totalFactura * pct / 100;
      pctCell.textContent = pct.toFixed(1) + '%';
      sistemaCell.textContent = fmtCOP(valorSistema);
      // Solo precarga el valor final si el admin no lo ha editado a mano
      // todavía (evita pisar un ajuste ya hecho al recalcular).
      if (!valorFinalInput.dataset.tocado) {
        valorFinalInput.value = Math.round(valorSistema);
      }
    } else {
      // Sin medidor: no hay % individual automático — el admin define el
      // valor final de cada una a mano, viendo el remanente total arriba.
      pctCell.textContent = '—';
      sistemaCell.textContent = '—';
    }
  });

  actualizarTotalFinal();
}

// Marca un campo "valor final" como editado a mano, para que
// recalcularConsolidado() no lo sobreescriba después.
document.addEventListener('input', function(e) {
  if (e.target.classList && e.target.classList.contains('valor-final-input')) {
    e.target.dataset.tocado = '1';
  }
});

function actualizarTotalFinal() {
  const totalFactura = parseFloat(document.getElementById('totalFactura')?.value) || 0;
  let sumaFinal = 0;
  let sumaConsumo = 0;
  document.querySelectorAll('.valor-final-input').forEach(inp => {
    sumaFinal += parseFloat(inp.value || '0');
  });
  document.querySelectorAll('.consolidado-row[data-con-medidor="1"]').forEach(row => {
    sumaConsumo += parseFloat(row.dataset.consumo || '0');
  });

  const totalFinalCell = document.getElementById('totalFinalCell');
  if (totalFinalCell) {
    totalFinalCell.textContent = fmtCOP(sumaFinal);
    // Celda dinámica verde si hace match con el total real de la factura
    // (tolerancia de $1 por redondeos), como en el boceto original.
    const match = totalFactura > 0 && Math.abs(sumaFinal - totalFactura) <= 1;
    totalFinalCell.style.background = match ? '#dcfce7' : (totalFactura > 0 ? '#fef3c7' : 'transparent');
    totalFinalCell.style.color = match ? '#166534' : (totalFactura > 0 ? '#92400e' : 'inherit');
  }
  const totalConsumoCell = document.getElementById('totalConsumoCell');
  if (totalConsumoCell) totalConsumoCell.textContent = sumaConsumo.toFixed(2);
}

// Sincronizar fecha límite con el campo hidden del form de guardado, y
// disparar el primer cálculo al cargar la pestaña (por si ya hay valores
// precargados desde un guardado previo — hoy no se persisten los datos de
// cabecera entre cargas, así que esto principalmente deja todo en $0/0%
// hasta que el admin digite los datos de la factura).
document.addEventListener('DOMContentLoaded', function() {
  const fechaInput = document.getElementById('fechaLimiteFactura');
  const fechaHidden = document.getElementById('fechaLimiteHidden');
  if (fechaInput && fechaHidden) {
    fechaInput.addEventListener('change', () => { fechaHidden.value = fechaInput.value; });
  }
  if (document.getElementById('consolidadoTabla')) {
    recalcularConsolidado();
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
