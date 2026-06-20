<?php
// =============================================================
//  PropertyRent — Admin: Servicios Públicos
//
//  Responsabilidades de este módulo:
//    - Gestión centralizada de todos los cobros de servicios
//    - Editar valor, estado o tipo de un servicio registrado
//    - Registrar pago de un servicio (con o sin comprobante)
//    - Subir factura del servicio (PDF/imagen)
//    - Agregar un servicio manualmente a cualquier unidad
//    - Vista consolidada: por período, por inmueble, por tipo
//    - Totales y resumen de cobros vs. pagados vs. pendientes
//
//  Complementa a meters.php que se encarga de
//  la captura de lecturas de medidor y el cálculo de consumo.
// =============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$user = dpr_current_user();
$msg  = $type = '';

// ---------------------------------------------------------------
// POST: acciones sobre servicios
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // ---- Agregar servicio manual ----
    if ($act === 'add_service') {
        $unit_id  = (int)$_POST['unit_id'];
        $lease_id = (int)($_POST['lease_id'] ?? 0) ?: null;
        $tipo     = $_POST['tipo']    ?? 'otro';
        $periodo  = $_POST['periodo'] ?? date('Y-m');
        $valor    = (float)$_POST['valor'];
        $incluido = isset($_POST['incluido']) ? 1 : 0;
        $desc     = trim($_POST['descripcion'] ?? '');
        $responsable_tipo = $lease_id ? 'inquilino' : 'propietario';

        if (!$unit_id || $valor < 0) {
            $msg = 'Unidad y valor son obligatorios.'; $type = 'danger';
        } else {
            // Verificar si ya existe para ese período y tipo
            $existe = db_query($mysqli,
                "SELECT id FROM public_services WHERE unit_id=? AND tipo=? AND periodo=?",
                'iss', [$unit_id, $tipo, $periodo]);
            if ($existe) {
                $msg = "Ya existe un cobro de '$tipo' para esa unidad en $periodo. Edítalo desde la tabla.";
                $type = 'warn';
            } else {
                $r = db_execute($mysqli,
                    "INSERT INTO public_services (unit_id, lease_id, responsable_tipo, tipo, periodo, valor, incluido, estado)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente')",
                    'iisssdi', [$unit_id, $lease_id, $responsable_tipo, $tipo, $periodo, $valor, $incluido]);
                dpr_audit_log($mysqli, $user['id'], 'INSERT', 'public_services', $r['insert_id'],
                    "Agregó servicio $tipo unidad=$unit_id periodo=$periodo valor=$valor responsable=$responsable_tipo");
                $msg = 'Servicio agregado correctamente.'; $type = 'success';
            }
        }
    }

    // ---- Editar servicio ----
    if ($act === 'edit_service') {
        $sid    = (int)$_POST['service_id'];
        $valor  = (float)$_POST['valor'];
        $tipo   = $_POST['tipo']    ?? 'otro';
        $estado = $_POST['estado']  ?? 'pendiente';
        $incl   = isset($_POST['incluido']) ? 1 : 0;

        db_execute($mysqli,
            "UPDATE public_services SET valor=?, tipo=?, estado=?, incluido=? WHERE id=?",
            'dssii', [$valor, $tipo, $estado, $incl, $sid]);
        dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'public_services', $sid,
            "Editó servicio sid=$sid valor=$valor estado=$estado");
        $msg = 'Servicio actualizado.'; $type = 'success';
    }

    // ---- Registrar pago de servicio ----
    if ($act === 'pay_service') {
        $sid      = (int)$_POST['service_id'];
        $metodo   = $_POST['metodo']   ?? 'efectivo';
        $ref      = trim($_POST['referencia'] ?? '');
        $nota     = trim($_POST['nota']       ?? '');
        $factura_url = null;

        // Subir factura si viene adjunta
        if (!empty($_FILES['factura']['name'])) {
            $up = dpr_upload_image($_FILES['factura'], 'facturas');
            if (!$up['success']) {
                $msg = 'Error al subir la factura: ' . $up['error']; $type = 'danger';
                goto render;
            }
            $factura_url = $up['url'];
        }

        db_execute($mysqli,
            "UPDATE public_services
             SET estado='pagado',
                 factura_url = COALESCE(?, factura_url)
             WHERE id=?",
            'si', [$factura_url, $sid]);
        dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'public_services', $sid,
            "Pago servicio sid=$sid metodo=$metodo ref=$ref");
        $msg = 'Servicio marcado como pagado.'; $type = 'success';
    }

    // ---- Subir/reemplazar factura a un servicio ya registrado ----
    if ($act === 'upload_factura') {
        $sid = (int)$_POST['service_id'];
        if (!empty($_FILES['factura']['name'])) {
            $up = dpr_upload_image($_FILES['factura'], 'facturas');
            if ($up['success']) {
                db_execute($mysqli,
                    "UPDATE public_services SET factura_url=? WHERE id=?",
                    'si', [$up['url'], $sid]);
                dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'public_services', $sid,
                    "Subió factura: {$up['filename']}");
                $msg = 'Factura adjuntada al servicio.'; $type = 'success';
            } else {
                $msg = $up['error']; $type = 'danger';
            }
        }
    }

    // ---- Eliminar servicio ----
    if ($act === 'delete_service') {
        $sid = (int)$_POST['service_id'];
        // Solo se pueden eliminar servicios pendientes
        $chk = db_query($mysqli, "SELECT estado FROM public_services WHERE id=?", 'i', [$sid]);
        if ($chk && $chk[0]['estado'] !== 'pagado') {
            db_execute($mysqli, "DELETE FROM public_services WHERE id=?", 'i', [$sid]);
            dpr_audit_log($mysqli, $user['id'], 'DELETE', 'public_services', $sid, 'Eliminó servicio');
            $msg = 'Servicio eliminado.'; $type = 'success';
        } else {
            $msg = 'No se puede eliminar un servicio ya pagado.'; $type = 'danger';
        }
    }
}

render:

// ---------------------------------------------------------------
// Filtros de listado
// ---------------------------------------------------------------
$f_periodo = $_GET['periodo']     ?? date('Y-m');
$f_prop    = (int)($_GET['property_id'] ?? 0);
$f_tipo    = $_GET['tipo']        ?? '';
$f_estado  = $_GET['estado']      ?? '';

$where_parts = ["ps.periodo = '$f_periodo'"];
if ($f_prop)   $where_parts[] = "pr.id = $f_prop";
if ($f_tipo)   $where_parts[] = "ps.tipo = '"   . $mysqli->real_escape_string($f_tipo)   . "'";
if ($f_estado) $where_parts[] = "ps.estado = '" . $mysqli->real_escape_string($f_estado) . "'";
$where = implode(' AND ', $where_parts);

$services = db_query($mysqli,
    "SELECT ps.*,
            un.nombre AS unidad,
            pr.nombre AS inmueble,
            pr.id     AS property_id,
            COALESCE(CONCAT(u.nombre,' ',u.apellido), 'Propietario') AS inquilino,
            u.email   AS tenant_email,
            mr.lectura_anterior, mr.lectura_actual, mr.consumo, mr.tarifa
     FROM public_services ps
     JOIN units un      ON un.id  = ps.unit_id
     JOIN properties pr ON pr.id  = un.property_id
     LEFT JOIN leases l ON l.id   = ps.lease_id
     LEFT JOIN users u  ON u.id   = l.tenant_id
     LEFT JOIN meter_readings mr
            ON mr.unit_id = ps.unit_id
           AND mr.tipo    = ps.tipo
           AND mr.periodo = ps.periodo
     WHERE $where
     ORDER BY pr.nombre, un.nombre, ps.tipo");

// Totales para KPIs
$totales = db_query($mysqli,
    "SELECT
        SUM(ps.valor)                                                    AS total_facturado,
        SUM(CASE WHEN ps.estado='pagado'   THEN ps.valor ELSE 0 END)    AS total_pagado,
        SUM(CASE WHEN ps.estado='pendiente'THEN ps.valor ELSE 0 END)    AS total_pendiente,
        COUNT(*)                                                          AS total_registros,
        COUNT(CASE WHEN ps.estado='pendiente' THEN 1 END)                AS n_pendientes,
        COUNT(CASE WHEN ps.incluido=1 THEN 1 END)                        AS n_incluidos
     FROM public_services ps
     JOIN units un      ON un.id = ps.unit_id
     JOIN properties pr ON pr.id = un.property_id
     WHERE $where");
$tot = $totales[0] ?? [];

// Resumen agrupado por tipo
$por_tipo = db_query($mysqli,
    "SELECT ps.tipo,
            COUNT(*)              AS n,
            SUM(ps.valor)         AS total,
            SUM(CASE WHEN ps.estado='pagado' THEN ps.valor ELSE 0 END) AS pagado
     FROM public_services ps
     JOIN units un      ON un.id  = ps.unit_id
     JOIN properties pr ON pr.id  = un.property_id
     WHERE $where
     GROUP BY ps.tipo ORDER BY total DESC");

// Datos para formulario de agregar: inquilinos con contrato activo
// + unidades ocupadas por el propietario (sin contrato)
$all_props   = db_query($mysqli, "SELECT id, nombre FROM properties WHERE estado='activo' ORDER BY nombre");
$units_lease = db_query($mysqli,
    "SELECT u.id AS unit_id, l.id AS lease_id,
            CONCAT(pr.nombre,' / ',u.nombre,' — ',
                   COALESCE(CONCAT(us.nombre,' ',us.apellido), 'Propietario (sin arriendo)')) AS label
     FROM units u
     JOIN properties pr ON pr.id = u.property_id
     LEFT JOIN leases l ON l.unit_id = u.id AND l.estado='activo'
     LEFT JOIN users us ON us.id = l.tenant_id
     WHERE u.estado = 'ocupada'
       AND u.ocupante_tipo IN ('inquilino','propietario')
     ORDER BY pr.nombre, u.nombre");

$tipos_servicio = ['agua','energia','gas','internet','administracion','otro'];

$page_title  = 'Servicios Públicos';
$active_menu = 'services';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<!-- ===== CABECERA ===== -->
<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Servicios públicos</div>
    <div class="dpr-page-sub">Cobros, pagos y facturas · Período: <?= h($f_periodo) ?></div>
  </div>
  <button class="dpr-btn dpr-btn--primary" onclick="openModal('addModal')">+ Agregar servicio</button>
</div>

<!-- ===== KPIs ===== -->
<div class="dpr-kpi-grid" style="grid-template-columns:repeat(4,1fr)">
  <div class="dpr-kpi dpr-kpi--info">
    <div class="dpr-kpi__label">Total facturado</div>
    <div class="dpr-kpi__value"><?= fmt_money((float)($tot['total_facturado'] ?? 0)) ?></div>
    <div class="dpr-kpi__sub"><?= (int)($tot['total_registros'] ?? 0) ?> registros · <?= h($f_periodo) ?></div>
  </div>
  <div class="dpr-kpi dpr-kpi--success">
    <div class="dpr-kpi__label">Cobrado</div>
    <div class="dpr-kpi__value"><?= fmt_money((float)($tot['total_pagado'] ?? 0)) ?></div>
    <div class="dpr-kpi__sub">Servicios pagados</div>
  </div>
  <div class="dpr-kpi dpr-kpi--warn">
    <div class="dpr-kpi__label">Pendiente</div>
    <div class="dpr-kpi__value"><?= fmt_money((float)($tot['total_pendiente'] ?? 0)) ?></div>
    <div class="dpr-kpi__sub"><?= (int)($tot['n_pendientes'] ?? 0) ?> cobros por pagar</div>
  </div>
  <div class="dpr-kpi">
    <div class="dpr-kpi__label">Incluidos en arriendo</div>
    <div class="dpr-kpi__value"><?= (int)($tot['n_incluidos'] ?? 0) ?></div>
    <div class="dpr-kpi__sub">Sin cobro adicional</div>
  </div>
</div>

<!-- ===== RESUMEN POR TIPO ===== -->
<?php if ($por_tipo): ?>
<div class="dpr-card" style="margin-bottom:18px">
  <div class="dpr-card__title">Resumen por tipo de servicio · <?= h($f_periodo) ?></div>
  <div style="display:flex;flex-wrap:wrap;gap:10px">
    <?php foreach ($por_tipo as $pt):
      $pct_pag = $pt['total'] > 0 ? round($pt['pagado'] / $pt['total'] * 100) : 0;
      $icon = match($pt['tipo']) {
        'agua'          => '💧',
        'energia'       => '⚡',
        'gas'           => '🔥',
        'internet'      => '📡',
        'administracion'=> '🏢',
        default         => '📋',
      };
    ?>
    <div style="background:var(--gray-card);border:0.5px solid var(--border);border-radius:10px;padding:14px 18px;min-width:160px;flex:1">
      <div style="font-size:20px;margin-bottom:4px"><?= $icon ?></div>
      <div style="font-size:12px;font-weight:600;color:var(--navy);text-transform:capitalize"><?= h($pt['tipo']) ?></div>
      <div style="font-size:16px;font-weight:700;color:var(--navy);margin:4px 0"><?= fmt_money($pt['total']) ?></div>
      <div style="background:#e2e8f0;border-radius:4px;height:5px;margin-bottom:4px">
        <div style="width:<?= $pct_pag ?>%;background:#10b981;height:5px;border-radius:4px"></div>
      </div>
      <div style="font-size:11px;color:var(--text-muted)"><?= $pct_pag ?>% cobrado · <?= $pt['n'] ?> unidades</div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ===== FILTROS ===== -->
<form method="GET" class="dpr-card" style="padding:12px 18px;margin-bottom:16px">
  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
    <div class="dpr-form-group">
      <label class="dpr-label">Período</label>
      <input type="month" name="periodo" class="dpr-input" value="<?= h($f_periodo) ?>">
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Inmueble</label>
      <select name="property_id" class="dpr-select" style="width:180px">
        <option value="">Todos</option>
        <?php foreach ($all_props as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $f_prop==$p['id']?'selected':'' ?>><?= h($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Tipo</label>
      <select name="tipo" class="dpr-select">
        <option value="">Todos</option>
        <?php foreach ($tipos_servicio as $t): ?>
        <option value="<?= $t ?>" <?= $f_tipo===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Estado</label>
      <select name="estado" class="dpr-select">
        <option value="">Todos</option>
        <option value="pendiente" <?= $f_estado==='pendiente'?'selected':'' ?>>Pendiente</option>
        <option value="pagado"    <?= $f_estado==='pagado'   ?'selected':'' ?>>Pagado</option>
        <option value="incluido"  <?= $f_estado==='incluido' ?'selected':'' ?>>Incluido</option>
      </select>
    </div>
    <button class="dpr-btn dpr-btn--primary">Filtrar</button>
    <a href="?" class="dpr-btn dpr-btn--secondary">Limpiar</a>
  </div>
</form>

<!-- ===== TABLA PRINCIPAL ===== -->
<div class="dpr-card">
  <div class="dpr-card__title">
    Detalle de servicios
    <span class="dpr-pill dpr-pill--neutral" style="margin-left:8px;font-size:11px"><?= count($services) ?> registros</span>
  </div>
  <?php if (!$services): ?>
  <div style="text-align:center;padding:32px;color:var(--text-muted)">
    No hay servicios registrados para los filtros seleccionados.<br>
    <button class="dpr-btn dpr-btn--secondary" style="margin-top:12px" onclick="openModal('addModal')">Agregar servicio</button>
  </div>
  <?php else: ?>
  <div class="dpr-table-wrap">
    <table class="dpr-table" id="servicesTable">
      <thead>
        <tr>
          <th>Inmueble / Unidad</th>
          <th>Inquilino</th>
          <th>Tipo</th>
          <th>Período</th>
          <th>Consumo</th>
          <th>Valor</th>
          <th>Estado</th>
          <th>Factura</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($services as $s):
          $pill = match($s['estado']) {
            'pagado'   => 'ok',
            'pendiente'=> 'warn',
            'incluido' => 'info',
            default    => 'neutral',
          };
          $icon_tipo = match($s['tipo']) {
            'agua'          => '💧',
            'energia'       => '⚡',
            'gas'           => '🔥',
            'internet'      => '📡',
            'administracion'=> '🏢',
            default         => '📋',
          };
          $tiene_consumo = $s['consumo'] !== null;
        ?>
        <tr id="svc-row-<?= $s['id'] ?>">
          <td>
            <div style="font-weight:500;font-size:13px"><?= h($s['inmueble']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= h($s['unidad']) ?></div>
          </td>
          <td style="font-size:13px">
            <?= h($s['inquilino']) ?>
            <?php if (($s['responsable_tipo'] ?? 'inquilino') === 'propietario'): ?>
              <span class="dpr-pill dpr-pill--info" style="font-size:10px;margin-left:4px">Propietario</span>
            <?php endif; ?>
          </td>
          <td>
            <span style="font-size:15px"><?= $icon_tipo ?></span>
            <span style="font-size:13px;margin-left:4px;text-transform:capitalize"><?= h($s['tipo']) ?></span>
          </td>
          <td style="font-size:13px"><?= h($s['periodo']) ?></td>
          <td style="font-size:12px;color:var(--text-muted)">
            <?php if ($tiene_consumo): ?>
              <?= number_format($s['lectura_anterior'],1) ?> → <?= number_format($s['lectura_actual'],1) ?><br>
              <strong><?= number_format($s['consumo'],2) ?></strong> uds
            <?php else: ?>
              <span style="color:var(--text-muted)">Sin medidor</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($s['incluido']): ?>
              <span class="dpr-pill dpr-pill--info">Incluido</span>
            <?php else: ?>
              <strong style="font-size:14px"><?= fmt_money($s['valor']) ?></strong>
            <?php endif; ?>
          </td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst($s['estado']) ?></span></td>
          <td>
            <?php if ($s['factura_url']): ?>
              <a href="<?= h($s['factura_url']) ?>" target="_blank"
                 class="dpr-btn dpr-btn--sm dpr-btn--secondary" style="font-size:11px">
                 Ver factura
              </a>
            <?php else: ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Subir factura"
                onclick="openUploadFactura(<?= $s['id'] ?>)">
                <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                  <path d="M6.5 1v7M4 5l2.5-3L9 5M1.5 10h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            <?php endif; ?>
          </td>
          <td>
            <div class="dpr-flex">
              <?php if ($s['estado'] === 'pendiente' && !$s['incluido']): ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--primary"
                onclick='openPayModal(<?= json_encode([
                  "id"=>$s['id'], "tipo"=>$s['tipo'],
                  "unidad"=>$s['unidad'], "inmueble"=>$s['inmueble'],
                  "inquilino"=>$s['inquilino'], "valor"=>$s['valor'],
                  "periodo"=>$s['periodo']
                ]) ?>)'>
                Pagar
              </button>
              <?php endif; ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Editar"
                onclick='openEditModal(<?= json_encode([
                  "id"=>$s['id'], "tipo"=>$s['tipo'],
                  "valor"=>$s['valor'], "estado"=>$s['estado'],
                  "incluido"=>(bool)$s['incluido'],
                  "desc"=>""
                ]) ?>)'>
                <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                  <path d="M8.5 2l2.5 2.5L4 11H1.5V8.5L8.5 2Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                </svg>
              </button>
              <?php if ($s['estado'] !== 'pagado'): ?>
              <form method="POST" style="display:inline"
                onsubmit="return confirm('¿Eliminar este cobro de <?= ucfirst(h($s['tipo'])) ?>?')">
                <input type="hidden" name="action"     value="delete_service">
                <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
                <button class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Eliminar"
                  style="color:#dc2626">
                  <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                    <path d="M1.5 3.5h10M4.5 3.5V2h4v1.5M5 6v4M8 6v4M2.5 3.5l1 8h6l1-8"
                      stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Totales del período filtrado -->
  <div style="display:flex;justify-content:flex-end;gap:24px;padding:12px 14px;border-top:0.5px solid var(--border);font-size:13px">
    <span class="dpr-text-muted">Total período:</span>
    <span>Facturado: <strong><?= fmt_money((float)($tot['total_facturado'] ?? 0)) ?></strong></span>
    <span style="color:#065f46">Cobrado: <strong><?= fmt_money((float)($tot['total_pagado'] ?? 0)) ?></strong></span>
    <span style="color:#d97706">Pendiente: <strong><?= fmt_money((float)($tot['total_pendiente'] ?? 0)) ?></strong></span>
  </div>
  <?php endif; ?>
</div>


<!-- ============================================================
     MODAL: Agregar servicio manual
     ============================================================ -->
<div class="dpr-modal-backdrop" id="addModal">
  <div class="dpr-modal" style="width:560px">
    <button class="dpr-modal__close" onclick="closeModal('addModal')">&times;</button>
    <div class="dpr-modal__title">Agregar cobro de servicio</div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px">
      Usa este formulario para agregar servicios sin medidor (internet, administración, etc.)
      o para corregir un cobro manualmente.<br>
      Para servicios con medidor, usa
      <a href="meters.php" style="color:var(--blue)">Medidores</a>.
    </p>
    <form method="POST" id="addForm">
      <input type="hidden" name="action" value="add_service">
      <div class="dpr-form-grid">
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Unidad / Inquilino *</label>
          <select name="unit_id" id="addUnit" class="dpr-select" required
            onchange="fillLeaseId(this)">
            <option value="">Seleccionar...</option>
            <?php foreach ($units_lease as $ul): ?>
            <option value="<?= $ul['unit_id'] ?>" data-lease="<?= $ul['lease_id'] ?>">
              <?= h($ul['label']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="lease_id" id="addLeaseId">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Tipo de servicio *</label>
          <select name="tipo" class="dpr-select" required>
            <?php foreach ($tipos_servicio as $t): ?>
            <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Período *</label>
          <input type="month" name="periodo" class="dpr-input"
            value="<?= h($f_periodo) ?>" required>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Valor a cobrar (COP) *</label>
          <input type="number" name="valor" class="dpr-input"
            required min="0" step="100" placeholder="Ej: 48000">
        </div>
        <div class="dpr-form-group" style="align-self:flex-end">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="incluido">
            Incluido en el arriendo (no cobrar)
          </label>
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Descripción / Nota (opcional)</label>
          <input type="text" name="descripcion" class="dpr-input"
            placeholder="Ej: Internet fibra óptica mes de abril">
        </div>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary"
          onclick="closeModal('addModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Agregar servicio</button>
      </div>
    </form>
  </div>
</div>


<!-- ============================================================
     MODAL: Registrar pago de servicio
     ============================================================ -->
<div class="dpr-modal-backdrop" id="payModal">
  <div class="dpr-modal" style="width:480px">
    <button class="dpr-modal__close" onclick="closeModal('payModal')">&times;</button>
    <div class="dpr-modal__title">Registrar pago de servicio</div>
    <div id="payInfo" style="background:#f8fafc;border-radius:8px;padding:14px 16px;font-size:13px;margin-bottom:16px"></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="pay_service">
      <input type="hidden" name="service_id" id="payServiceId">
      <div class="dpr-form-grid">
        <div class="dpr-form-group">
          <label class="dpr-label">Método de pago</label>
          <select name="metodo" class="dpr-select">
            <?php foreach (['efectivo','transferencia','pse','tarjeta','nequi','daviplata','otro'] as $m): ?>
            <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Referencia / No. comprobante</label>
          <input type="text" name="referencia" class="dpr-input"
            placeholder="Opcional">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Adjuntar factura o comprobante (PDF / imagen)</label>
          <input type="file" name="factura" class="dpr-input"
            accept=".pdf,.jpg,.jpeg,.png,.webp">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Nota interna</label>
          <textarea name="nota" class="dpr-textarea" style="height:55px"
            placeholder="Observaciones opcionales..."></textarea>
        </div>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary"
          onclick="closeModal('payModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Confirmar pago</button>
      </div>
    </form>
  </div>
</div>


<!-- ============================================================
     MODAL: Editar servicio
     ============================================================ -->
<div class="dpr-modal-backdrop" id="editModal">
  <div class="dpr-modal" style="width:420px">
    <button class="dpr-modal__close" onclick="closeModal('editModal')">&times;</button>
    <div class="dpr-modal__title">Editar servicio</div>
    <form method="POST">
      <input type="hidden" name="action"     value="edit_service">
      <input type="hidden" name="service_id" id="editServiceId">
      <div class="dpr-form-grid">
        <div class="dpr-form-group">
          <label class="dpr-label">Tipo</label>
          <select name="tipo" id="editTipo" class="dpr-select">
            <?php foreach ($tipos_servicio as $t): ?>
            <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Estado</label>
          <select name="estado" id="editEstado" class="dpr-select">
            <option value="pendiente">Pendiente</option>
            <option value="pagado">Pagado</option>
            <option value="incluido">Incluido</option>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Valor (COP)</label>
          <input type="number" name="valor" id="editValor"
            class="dpr-input" min="0" step="100">
        </div>
        <div class="dpr-form-group" style="align-self:flex-end">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="incluido" id="editIncluido">
            Incluido en arriendo
          </label>
        </div>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary"
          onclick="closeModal('editModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>


<!-- ============================================================
     MODAL: Subir factura a servicio existente
     ============================================================ -->
<div class="dpr-modal-backdrop" id="facturaModal">
  <div class="dpr-modal" style="width:400px">
    <button class="dpr-modal__close" onclick="closeModal('facturaModal')">&times;</button>
    <div class="dpr-modal__title">Adjuntar factura del servicio</div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">
      Sube la factura original del servicio (PDF o imagen). Quedará disponible
      para consulta del administrador y del inquilino.
    </p>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="upload_factura">
      <input type="hidden" name="service_id" id="facturaServiceId">
      <div class="dpr-form-group" style="margin-bottom:16px">
        <label class="dpr-label">Archivo * (PDF, JPG, PNG · máx. 5 MB)</label>
        <div class="dpr-dropzone" style="cursor:pointer"
             onclick="document.getElementById('facturaFile').click()"
             ondragover="event.preventDefault();this.classList.add('dragover')"
             ondragleave="this.classList.remove('dragover')"
             ondrop="handleFacturaDrop(event)">
          <div class="dpr-dropzone__text">Arrastra o haz clic para seleccionar</div>
          <div id="facturaPreview" style="margin-top:8px;font-size:13px;color:var(--blue)"></div>
        </div>
        <input type="file" id="facturaFile" name="factura"
          accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none"
          onchange="document.getElementById('facturaPreview').textContent = this.files[0]?.name ?? ''" required>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary"
          onclick="closeModal('facturaModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Subir factura</button>
      </div>
    </form>
  </div>
</div>


<script>
// ---- Modal helpers ----
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.dpr-modal-backdrop').forEach(el =>
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); })
);

// ---- Agregar: auto-fill lease_id al cambiar unidad ----
function fillLeaseId(sel) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('addLeaseId').value = opt.dataset.lease || '';
}

// ---- Modal de pago ----
function openPayModal(svc) {
  document.getElementById('payServiceId').value = svc.id;
  document.getElementById('payInfo').innerHTML =
    `<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
       <span style="color:var(--text-muted)">Servicio:</span>
       <strong style="text-transform:capitalize">${svc.tipo}</strong>
       <span style="color:var(--text-muted)">Unidad:</span>
       <span>${escHtml(svc.inmueble + ' / ' + svc.unidad)}</span>
       <span style="color:var(--text-muted)">Inquilino:</span>
       <span>${escHtml(svc.inquilino)}</span>
       <span style="color:var(--text-muted)">Período:</span>
       <span>${svc.periodo}</span>
       <span style="color:var(--text-muted)">Valor:</span>
       <strong style="color:#0f2d52;font-size:15px">$${Number(svc.valor).toLocaleString('es-CO')}</strong>
     </div>`;
  openModal('payModal');
}

// ---- Modal de edición ----
function openEditModal(svc) {
  document.getElementById('editServiceId').value = svc.id;
  document.getElementById('editTipo').value      = svc.tipo;
  document.getElementById('editEstado').value    = svc.estado;
  document.getElementById('editValor').value     = svc.valor;
  document.getElementById('editIncluido').checked= svc.incluido;
  openModal('editModal');
}

// ---- Modal factura ----
function openUploadFactura(id) {
  document.getElementById('facturaServiceId').value = id;
  document.getElementById('facturaPreview').textContent = '';
  document.getElementById('facturaFile').value = '';
  openModal('facturaModal');
}

function handleFacturaDrop(e) {
  e.preventDefault();
  e.currentTarget.classList.remove('dragover');
  const f = e.dataTransfer.files[0];
  if (f) {
    document.getElementById('facturaFile').files = e.dataTransfer.files;
    document.getElementById('facturaPreview').textContent = f.name;
  }
}

function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
