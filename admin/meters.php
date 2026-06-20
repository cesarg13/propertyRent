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
    }

    // Registrar factura global del inmueble (agua/energía de todo el edificio)
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

        // Obtener unidades ocupadas (con contrato de inquilino O con propietario sin contrato)
        $unidades = db_query($mysqli,
            "SELECT u.id AS unit_id, l.id AS lease_id, u.area_m2,
                    IF(l.id IS NULL, 'propietario', 'inquilino') AS responsable_tipo
             FROM units u
             LEFT JOIN leases l ON l.unit_id = u.id AND l.estado = 'activo'
             WHERE u.property_id = ? AND u.estado = 'ocupada'
               AND u.ocupante_tipo IN ('inquilino','propietario')",
            'i', [$property_id]);

        if ($unidades) {
            if ($distribucion === 'proporcional') {
                $area_total = array_sum(array_column($unidades, 'area_m2')) ?: count($unidades);
                foreach ($unidades as $un) {
                    $proporcion = ($un['area_m2'] ?: ($area_total/count($unidades))) / $area_total;
                    $valor_un   = round($valor_total * $proporcion, 0);
                    db_execute($mysqli,
                        "INSERT INTO public_services (unit_id,lease_id,responsable_tipo,tipo,periodo,valor,incluido,estado)
                         VALUES (?,?,?,?,?,?,0,'pendiente')
                         ON DUPLICATE KEY UPDATE valor=VALUES(valor), responsable_tipo=VALUES(responsable_tipo)",
                        'iisssd', [$un['unit_id'],$un['lease_id'],$un['responsable_tipo'],$tipo,$periodo,$valor_un]);
                }
            } else {
                $valor_un = round($valor_total / count($unidades), 0);
                foreach ($unidades as $un) {
                    db_execute($mysqli,
                        "INSERT INTO public_services (unit_id,lease_id,responsable_tipo,tipo,periodo,valor,incluido,estado)
                         VALUES (?,?,?,?,?,?,0,'pendiente')
                         ON DUPLICATE KEY UPDATE valor=VALUES(valor), responsable_tipo=VALUES(responsable_tipo)",
                        'iisssd', [$un['unit_id'],$un['lease_id'],$un['responsable_tipo'],$tipo,$periodo,$valor_un]);
                }
            }
            $msg = "Factura global registrada y distribuida entre " . count($unidades) . " unidades."; $type = 'success';
        } else {
            $msg = 'No hay unidades activas para distribuir la factura.'; $type = 'warn';
        }
    }

    // Marcar servicio como pagado
    if ($act === 'mark_paid') {
        $sid = (int)$_POST['service_id'];
        db_execute($mysqli, "UPDATE public_services SET estado='pagado' WHERE id=?", 'i', [$sid]);
        dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'public_services', $sid, 'Marcó servicio como pagado');
        $msg = 'Servicio marcado como pagado.'; $type = 'success';
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
  <?php foreach (['lecturas'=>'Lecturas por unidad','factura_global'=>'Factura global inmueble','historial'=>'Historial'] as $t=>$label): ?>
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

<?php else: ?>
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
