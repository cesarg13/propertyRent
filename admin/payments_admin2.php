<?php
// =============================================================
//  PropertyRent — Admin: Gestión de Pagos v2 (Ledger)
//  Rediseño completo: un solo botón "Nuevo Pago" (repartido por
//  concepto), un botón "Nueva Obligación", tabla de movimientos
//  consecutivos con paginación y filtros por inmueble/unidad/inquilino.
//
//  Tablas nuevas: obligations, payments_received, payment_applications
//  (ver 4_migration_ledger_pagos.sql). NO usa payments/payment_partials.
//  Convive con payments_admin.php (módulo viejo) durante la transición.
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$user = dpr_current_user();
$msg  = $type = '';
$CONCEPTOS = dpr_conceptos_ledger();

// ------------------------------------------------------------------
// AJAX: detalle de un pago (sus aplicaciones) — usado por modales
// ------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pago_detalle') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_GET['payment_id'] ?? 0);
    $pay = db_query($mysqli, "SELECT * FROM payments_received WHERE id=?", 'i', [$pid]);
    if (!$pay) { echo json_encode(['success' => false]); exit; }
    $apps = db_query($mysqli,
        "SELECT pa.*, o.periodo AS obl_periodo, o.fecha_limite AS obl_fecha_limite
         FROM payment_applications pa
         LEFT JOIN obligations o ON o.id = pa.obligation_id
         WHERE pa.payment_id = ? ORDER BY pa.created_at ASC", 'i', [$pid]);
    echo json_encode([
        'success' => true,
        'pago' => $pay[0],
        'aplicaciones' => $apps,
    ]);
    exit;
}

// ------------------------------------------------------------------
// AJAX: obligaciones pendientes de un lease (para armar el reparto
// del modal "Nuevo Pago" / aprobación de pago de inquilino)
// ------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'obligaciones_lease') {
    header('Content-Type: application/json; charset=utf-8');
    $lid = (int)($_GET['lease_id'] ?? 0);
    $rows = db_query($mysqli,
        "SELECT o.id, o.concepto, o.periodo, o.valor_efectivo, o.fecha_limite, o.origen,
                COALESCE(SUM(CASE WHEN pr.estado='aprobado' THEN pa.monto ELSE 0 END), 0) AS aplicado
         FROM obligations o
         LEFT JOIN payment_applications pa ON pa.obligation_id = o.id
         LEFT JOIN payments_received pr ON pr.id = pa.payment_id
         WHERE o.lease_id = ? AND o.estado = 'activa'
         GROUP BY o.id
         HAVING o.valor_efectivo > aplicado
         ORDER BY o.fecha_limite ASC, o.id ASC",
        'i', [$lid]);
    $out = array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'concepto' => $r['concepto'],
            'periodo' => $r['periodo'],
            'valor_efectivo' => (float)$r['valor_efectivo'],
            'aplicado' => (float)$r['aplicado'],
            'pendiente' => (float)$r['valor_efectivo'] - (float)$r['aplicado'],
            'fecha_limite' => $r['fecha_limite'],
            'label' => ($GLOBALS['CONCEPTOS'][$r['concepto']] ?? $r['concepto']) . ($r['periodo'] ? " ({$r['periodo']})" : ''),
        ];
    }, $rows);
    echo json_encode(['success' => true, 'obligaciones' => $out]);
    exit;
}

// ------------------------------------------------------------------
// AJAX: unidades de un inmueble (para cascada de filtros/selects)
// ------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'unidades_inmueble') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_GET['property_id'] ?? 0);
    $rows = $pid ? db_query($mysqli, "SELECT id, nombre FROM units WHERE property_id=? ORDER BY nombre", 'i', [$pid]) : [];
    echo json_encode(['success' => true, 'unidades' => $rows]);
    exit;
}

// ------------------------------------------------------------------
// POST handlers
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // --- Nueva Obligación ---
    if ($act === 'nueva_obligacion') {
        $lid          = (int)($_POST['lease_id'] ?? 0);
        $concepto     = $_POST['concepto'] ?? '';
        $periodo      = trim($_POST['periodo'] ?? '') ?: null;
        $valor        = (float)str_replace(['.', '$', ','], ['', '', ''], $_POST['valor'] ?? '0');
        $fecha_limite = trim($_POST['fecha_limite'] ?? '') ?: null;
        $nota         = trim($_POST['nota'] ?? '');

        if (!$lid) {
            $msg = 'Selecciona un contrato.'; $type = 'danger';
        } else {
            $r = dpr_crear_obligacion($mysqli, $lid, $concepto, $periodo, $valor, $fecha_limite, $nota, 'manual', null, $user['id']);
            if ($r['success']) { $msg = 'Obligación creada.'; $type = 'success'; }
            else               { $msg = $r['error'] ?? 'No se pudo crear la obligación.'; $type = 'danger'; }
        }
    }

    // --- Editar Obligación ---
    elseif ($act === 'editar_obligacion') {
        $oid          = (int)($_POST['obligation_id'] ?? 0);
        $valor_in     = trim($_POST['valor_ajustado'] ?? '');
        $valor_ajust  = $valor_in === '' ? null : (float)str_replace(['.', '$', ','], ['', '', ''], $valor_in);
        $fecha_limite = trim($_POST['fecha_limite'] ?? '') ?: null;
        $nota         = trim($_POST['nota'] ?? '');

        $r = dpr_editar_obligacion($mysqli, $oid, $valor_ajust, $fecha_limite, $nota, $user['id']);
        $msg = $r['success'] ? 'Obligación actualizada.' : ($r['error'] ?? 'No se pudo editar.');
        $type = $r['success'] ? 'success' : 'danger';
    }

    // --- Anular Obligación ---
    elseif ($act === 'anular_obligacion') {
        $oid    = (int)($_POST['obligation_id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        $force  = !empty($_POST['force']);

        $r = dpr_anular_obligacion($mysqli, $oid, $motivo, $user['id'], $force);
        if ($r['success']) {
            $msg = 'Obligación anulada.'; $type = 'success';
        } elseif (!empty($r['requiere_confirmacion'])) {
            $msg = $r['error'] . ' Si continúas de todas formas, marca "Forzar" y vuelve a intentar.'; $type = 'warn';
        } else {
            $msg = $r['error'] ?? 'No se pudo anular.'; $type = 'danger';
        }
    }

    // --- Nuevo Pago (admin, dividido en N conceptos) ---
    elseif ($act === 'nuevo_pago') {
        $lid      = (int)($_POST['lease_id'] ?? 0);
        $metodo   = $_POST['metodo'] ?? 'efectivo';
        $ref      = trim($_POST['referencia'] ?? '');
        $fecha_p  = $_POST['fecha_pago'] ?: date('Y-m-d');
        $nota     = trim($_POST['nota'] ?? '');
        $monto_total = (float)str_replace(['.', '$', ','], ['', '', ''], $_POST['monto_total'] ?? '0');

        // Conceptos: arrays paralelos concepto[]/monto[]/obligation_id[]
        $conceptos_in    = $_POST['concepto']       ?? [];
        $montos_in       = $_POST['monto_concepto']  ?? [];
        $obligaciones_in = $_POST['obligation_id']   ?? [];

        $aplicaciones = [];
        foreach ($conceptos_in as $i => $c) {
            $m = (float)str_replace(['.', '$', ','], ['', '', ''], $montos_in[$i] ?? '0');
            if ($m <= 0) continue;
            $oid = (int)($obligaciones_in[$i] ?? 0);
            $aplicaciones[] = [
                'obligation_id' => $oid > 0 ? $oid : null,
                'concepto' => $c,
                'monto' => $m,
            ];
        }

        $comp_url = null;
        if (!empty($_FILES['comprobante']['name'])) {
            $up = dpr_upload_image($_FILES['comprobante'], 'comprobantes');
            if (!$up['success']) {
                $msg = $up['error']; $type = 'danger';
                $aplicaciones = null; // abortar el registro si falló la subida
            } else {
                $comp_url = $up['url'];
            }
        }

        if ($aplicaciones !== null) {
            if (!$lid) {
                $msg = 'Selecciona un contrato.'; $type = 'danger';
            } else {
                $r = dpr_registrar_pago_ledger($mysqli, $lid, $monto_total, $metodo, $ref, $comp_url, $nota, $fecha_p, $user['id'], 'admin', $aplicaciones);
                if ($r['success']) { $msg = 'Pago registrado.'; $type = 'success'; }
                else               { $msg = $r['error'] ?? 'No se pudo registrar el pago.'; $type = 'danger'; }
            }
        }
    }

    // --- Aprobar pago de inquilino (define el reparto al aprobar) ---
    elseif ($act === 'aprobar_pago') {
        $pid  = (int)($_POST['payment_id'] ?? 0);
        $nota = trim($_POST['nota_admin'] ?? '');

        $conceptos_in    = $_POST['concepto']      ?? [];
        $montos_in       = $_POST['monto_concepto'] ?? [];
        $obligaciones_in = $_POST['obligation_id']  ?? [];

        $aplicaciones = [];
        foreach ($conceptos_in as $i => $c) {
            $m = (float)str_replace(['.', '$', ','], ['', '', ''], $montos_in[$i] ?? '0');
            if ($m <= 0) continue;
            $oid = (int)($obligaciones_in[$i] ?? 0);
            $aplicaciones[] = [
                'obligation_id' => $oid > 0 ? $oid : null,
                'concepto' => $c,
                'monto' => $m,
            ];
        }

        $r = dpr_aprobar_pago_ledger($mysqli, $pid, $aplicaciones, $nota, $user['id']);
        $msg = $r['success'] ? 'Pago aprobado y repartido.' : ($r['error'] ?? 'No se pudo aprobar.');
        $type = $r['success'] ? 'success' : 'danger';
    }

    // --- Rechazar pago de inquilino ---
    elseif ($act === 'rechazar_pago') {
        $pid    = (int)($_POST['payment_id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? 'Comprobante rechazado.');
        $r = dpr_rechazar_pago_ledger($mysqli, $pid, $motivo, $user['id']);
        $msg = $r['success'] ? 'Pago rechazado.' : ($r['error'] ?? 'No se pudo rechazar.');
        $type = $r['success'] ? 'success' : 'danger';
    }

    // --- Eliminar una aplicación individual (un concepto de un pago) ---
    elseif ($act === 'eliminar_aplicacion') {
        $aid    = (int)($_POST['application_id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        $r = dpr_eliminar_aplicacion($mysqli, $aid, $motivo, $user['id']);
        $msg = $r['success'] ? 'Aplicación eliminada.' : ($r['error'] ?? 'No se pudo eliminar.');
        $type = $r['success'] ? 'success' : 'danger';
    }
}

// ------------------------------------------------------------------
// Filtros (GET) — inmueble, unidad, inquilino, concepto + paginación
// ------------------------------------------------------------------
$f_prop     = (int)($_GET['property_id'] ?? 0);
$f_unit     = (int)($_GET['unit_id']     ?? 0);
$f_tenant   = (int)($_GET['tenant_id']   ?? 0);
$f_concepto = $_GET['concepto'] ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 25;

// Resolver lease_ids según filtros de inmueble/unidad/inquilino.
// Importante: por unidad/inmueble se incluyen TODOS los leases históricos
// (no solo el activo), porque el saldo y el historial deben sobrevivir a
// cambios de inquilino — el filtro no depende de quién ocupa hoy.
$lease_where = ['1=1'];
$lease_types = '';
$lease_params = [];
if ($f_tenant) { $lease_where[] = 'l.tenant_id = ?'; $lease_types .= 'i'; $lease_params[] = $f_tenant; }
if ($f_unit)   { $lease_where[] = 'l.unit_id = ?';   $lease_types .= 'i'; $lease_params[] = $f_unit; }
if ($f_prop)   { $lease_where[] = 'un.property_id = ?'; $lease_types .= 'i'; $lease_params[] = $f_prop; }

$lease_ids = [];
if ($f_tenant || $f_unit || $f_prop) {
    $rows = db_query($mysqli,
        "SELECT l.id FROM leases l JOIN units un ON un.id = l.unit_id WHERE " . implode(' AND ', $lease_where),
        $lease_types, $lease_params);
    $lease_ids = array_map(fn($r) => (int)$r['id'], $rows);
    // Si filtraron y no hay ningún lease que cumpla, forzamos lista vacía
    // pasando un id imposible — evita que "sin filtro" se interprete como "todos".
    if (!$lease_ids) $lease_ids = [-1];
}

$total_movs = dpr_ledger_movimientos_count($mysqli, $lease_ids, $f_concepto);
$total_pages = max(1, (int)ceil($total_movs / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$movimientos = dpr_ledger_movimientos($mysqli, $lease_ids, $f_concepto, $per_page, $offset);

// Enriquecer movimientos con datos de presentación (inquilino/unidad/inmueble)
// en una sola query adicional, evitando N+1.
$lease_ids_en_pagina = array_unique(array_column($movimientos, 'lease_id'));
$lease_info = [];
if ($lease_ids_en_pagina) {
    $in = implode(',', array_fill(0, count($lease_ids_en_pagina), '?'));
    $types = str_repeat('i', count($lease_ids_en_pagina));
    $rows = db_query($mysqli,
        "SELECT l.id AS lease_id, CONCAT(u.nombre,' ',u.apellido) AS inquilino, un.nombre AS unidad, pr.nombre AS inmueble
         FROM leases l JOIN users u ON u.id=l.tenant_id JOIN units un ON un.id=l.unit_id JOIN properties pr ON pr.id=un.property_id
         WHERE l.id IN ($in)", $types, $lease_ids_en_pagina);
    foreach ($rows as $r) { $lease_info[$r['lease_id']] = $r; }
}

// (Antes había aquí una query auxiliar a payments_received para mostrar
// datos del pago directamente en la tabla, pero el detalle de cada pago
// se carga vía AJAX bajo demanda — ver openPagoDetalle() — así que esa
// query no se necesita en el render inicial.)

// Saldo del lease cuando el filtro deja exactamente un lease identificable
// (ej: filtraron por inquilino o por unidad y solo hay un contrato activo)
$saldo_resumen = null;
if ($f_tenant || $f_unit) {
    $candidatos = array_values(array_unique($lease_ids));
    if (count($candidatos) === 1 && $candidatos[0] > 0) {
        $saldo_resumen = dpr_saldo_lease($mysqli, $candidatos[0]);
    }
}

// Selects auxiliares
$all_props = db_query($mysqli, "SELECT id, nombre FROM properties WHERE estado='activo' ORDER BY nombre");
$all_units = $f_prop ? db_query($mysqli, "SELECT id, nombre FROM units WHERE property_id=? ORDER BY nombre", 'i', [$f_prop])
                     : db_query($mysqli, "SELECT id, nombre FROM units ORDER BY nombre");
$all_tenants = db_query($mysqli, "SELECT DISTINCT u.id, u.nombre, u.apellido FROM users u JOIN leases l ON l.tenant_id=u.id WHERE u.rol='tenant' ORDER BY u.nombre");
$leases_activos = db_query($mysqli,
    "SELECT l.id, CONCAT(u.nombre,' ',u.apellido) AS inquilino, un.nombre AS unidad, pr.nombre AS inmueble
     FROM leases l JOIN users u ON u.id=l.tenant_id JOIN units un ON un.id=l.unit_id JOIN properties pr ON pr.id=un.property_id
     WHERE l.estado='activo' ORDER BY pr.nombre, un.nombre");

// Pagos de inquilino pendientes de validar (para badge / acceso rápido)
$pendientes_validar = db_query($mysqli, "SELECT COUNT(*) AS n FROM payments_received WHERE estado='validando'")[0]['n'] ?? 0;

$page_title  = 'Pagos (v2)';
$active_menu = 'payments';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<div class="dpr-alert dpr-alert--info" style="font-size:13px">
  Módulo en rediseño. El módulo anterior sigue disponible en
  <a href="<?= BASE_URL ?>/admin/payments_admin.php">Pagos (clásico)</a> mientras se valida este flujo nuevo.
  Las tablas <code>payments</code>/<code>payment_partials</code> no se modifican desde aquí.
</div>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Gestión de Pagos — Ledger</div>
    <div class="dpr-page-sub">
      <?= (int)$total_movs ?> movimientos
      <?php if ($pendientes_validar > 0): ?>
        &nbsp;·&nbsp;<span class="dpr-pill dpr-pill--warn"><?= (int)$pendientes_validar ?> pago(s) por validar</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="dpr-flex">
    <button type="button" class="dpr-btn dpr-btn--secondary" onclick="openObligacionModal()">+ Nueva Obligación</button>
    <button type="button" class="dpr-btn dpr-btn--primary" onclick="openPagoModal()">+ Nuevo Pago</button>
  </div>
</div>

<?php if ($saldo_resumen): ?>
<div class="dpr-card" style="background:#f8fafc">
  <div class="dpr-flex" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div><span class="dpr-text-muted">Total obligado:</span> <strong><?= fmt_money((float)$saldo_resumen['total_obligado']) ?></strong></div>
    <div><span class="dpr-text-muted">Total pagado:</span> <strong style="color:#059669"><?= fmt_money((float)$saldo_resumen['total_pagado']) ?></strong></div>
    <div>
      <span class="dpr-text-muted">Saldo:</span>
      <?php $sp = (float)$saldo_resumen['saldo_pendiente']; ?>
      <strong style="color:<?= $sp > 0 ? '#dc2626' : ($sp < 0 ? '#2563eb' : '#059669') ?>">
        <?= $sp == 0 ? 'Al día' : ($sp > 0 ? fmt_money($sp) . ' adeudado' : fmt_money(abs($sp)) . ' a favor') ?>
      </strong>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- FILTROS -->
<form method="GET" class="dpr-card" style="padding:14px 18px">
  <div class="dpr-form-grid dpr-form-grid--4" style="margin-bottom:0">
    <div class="dpr-form-group">
      <label class="dpr-label">Inmueble</label>
      <select name="property_id" class="dpr-select" id="filterProperty" onchange="dprLoadUnidades(this.value, true)">
        <option value="">Todos</option>
        <?php foreach ($all_props as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $f_prop == $p['id'] ? 'selected' : '' ?>><?= h($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Unidad</label>
      <select name="unit_id" class="dpr-select" id="filterUnit">
        <option value="">Todas</option>
        <?php foreach ($all_units as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $f_unit == $u['id'] ? 'selected' : '' ?>><?= h($u['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Inquilino</label>
      <select name="tenant_id" class="dpr-select">
        <option value="">Todos</option>
        <?php foreach ($all_tenants as $t): ?>
        <option value="<?= $t['id'] ?>" <?= $f_tenant == $t['id'] ? 'selected' : '' ?>><?= h($t['nombre'] . ' ' . $t['apellido']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Concepto</label>
      <select name="concepto" class="dpr-select">
        <option value="">Todos</option>
        <?php foreach ($CONCEPTOS as $k => $v): ?>
        <option value="<?= $k ?>" <?= $f_concepto === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div style="margin-top:10px;text-align:right">
    <button class="dpr-btn dpr-btn--primary">Filtrar</button>
    <a href="?" class="dpr-btn dpr-btn--secondary">Limpiar</a>
  </div>
</form>

<!-- TABLA DE MOVIMIENTOS (consecutivo, más reciente primero) -->
<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr>
          <th>Fecha</th><th>Tipo</th><th>Inquilino</th><th>Inmueble / Unidad</th>
          <th>Concepto</th><th>Periodo</th><th>Monto</th><th>Estado</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$movimientos): ?>
        <tr><td colspan="9" class="dpr-text-muted" style="text-align:center;padding:24px">Sin movimientos para los filtros aplicados.</td></tr>
        <?php endif; ?>
        <?php foreach ($movimientos as $m):
            $li = $lease_info[$m['lease_id']] ?? ['inquilino' => '—', 'unidad' => '—', 'inmueble' => '—'];
            $es_obligacion = $m['tipo_movimiento'] === 'obligacion';
            $concepto_label = $CONCEPTOS[$m['concepto']] ?? $m['concepto'];

            if ($es_obligacion) {
                $pill = $m['estado_detalle'] === 'anulada' ? 'neutral' : 'info';
                $estado_label = $m['estado_detalle'] === 'anulada' ? 'Anulada' : 'Obligación';
            } else {
                $pill = $m['estado_detalle'] === 'aprobado' ? 'ok' : ($m['estado_detalle'] === 'validando' ? 'warn' : 'danger');
                $estado_label = ucfirst($m['estado_detalle']);
            }
        ?>
        <tr>
          <td><?= date('d/m/Y', strtotime($m['created_at'])) ?></td>
          <td><?= $es_obligacion ? '<span class="dpr-pill dpr-pill--neutral">Obligación</span>' : '<span class="dpr-pill dpr-pill--ok">Pago</span>' ?></td>
          <td><?= h($li['inquilino']) ?></td>
          <td><?= h($li['inmueble'] . ' / ' . $li['unidad']) ?></td>
          <td><?= h($concepto_label) ?><?= !$es_obligacion && empty($m['obligation_id']) ? ' <span class="dpr-pill dpr-pill--warn" style="font-size:10px" title="Este dinero no está asignado a ninguna obligación todavía">Sin asignar</span>' : '' ?></td>
          <td><?= h($m['periodo'] ?? '—') ?></td>
          <td><strong><?= fmt_money((float)$m['monto']) ?></strong></td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= h($estado_label) ?></span></td>
          <td class="dpr-flex">
            <?php if ($es_obligacion && $m['estado_detalle'] === 'activa'): ?>
              <button type="button" class="dpr-btn dpr-btn--sm dpr-btn--secondary"
                onclick="openEditObligacion(<?= (int)$m['obligation_id'] ?>,'<?= h(addslashes($concepto_label)) ?>',<?= (float)$m['monto'] ?>,'<?= h($m['fecha_limite'] ?? '') ?>')">Editar</button>
              <button type="button" class="dpr-btn dpr-btn--sm dpr-btn--danger"
                onclick="openAnularObligacion(<?= (int)$m['obligation_id'] ?>,'<?= h(addslashes($concepto_label)) ?>')">Anular</button>
            <?php elseif (!$es_obligacion && $m['estado_detalle'] === 'validando'): ?>
              <button type="button" class="dpr-btn dpr-btn--sm dpr-btn--primary"
                onclick="openAprobarPago(<?= (int)$m['payment_id'] ?>,<?= (int)$m['lease_id'] ?>)">Revisar</button>
            <?php elseif (!$es_obligacion && $m['estado_detalle'] === 'aprobado'): ?>
              <button type="button" class="dpr-btn dpr-btn--sm dpr-btn--secondary"
                onclick="openPagoDetalle(<?= (int)$m['payment_id'] ?>)">Ver detalle</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- PAGINACIÓN -->
  <?php if ($total_pages > 1): ?>
  <div class="dpr-flex" style="justify-content:center;gap:6px;padding:14px;flex-wrap:wrap">
    <?php
      $qs = $_GET; unset($qs['page']);
      $base_qs = http_build_query($qs);
      $sep = $base_qs ? '&' : '';
    ?>
    <?php if ($page > 1): ?>
      <a class="dpr-btn dpr-btn--sm dpr-btn--secondary" href="?<?= $base_qs . $sep ?>page=<?= $page-1 ?>">‹ Anterior</a>
    <?php endif; ?>
    <span class="dpr-text-muted" style="align-self:center;font-size:13px">Página <?= $page ?> de <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?>
      <a class="dpr-btn dpr-btn--sm dpr-btn--secondary" href="?<?= $base_qs . $sep ?>page=<?= $page+1 ?>">Siguiente ›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ===================== MODAL: NUEVA OBLIGACIÓN ===================== -->
<div class="dpr-modal-backdrop" id="obligacionModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('obligacionModal')">&times;</button>
    <div class="dpr-modal__title">Nueva Obligación</div>
    <form method="POST">
      <input type="hidden" name="action" value="nueva_obligacion">
      <div class="dpr-form-grid">
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Contrato (inquilino / unidad)</label>
          <select name="lease_id" class="dpr-select" required>
            <option value="">Selecciona…</option>
            <?php foreach ($leases_activos as $l): ?>
            <option value="<?= $l['id'] ?>"><?= h($l['inquilino'] . ' — ' . $l['inmueble'] . ' / ' . $l['unidad']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Concepto</label>
          <select name="concepto" class="dpr-select" required>
            <?php foreach ($CONCEPTOS as $k => $v): ?>
            <option value="<?= $k ?>"><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Periodo (mes/año, opcional)</label>
          <input type="month" name="periodo" class="dpr-input">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Valor ($)</label>
          <input type="number" name="valor" class="dpr-input" min="1" step="1" required>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Fecha límite de pago</label>
          <input type="date" name="fecha_limite" class="dpr-input">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Nota (opcional)</label>
          <textarea name="nota" class="dpr-textarea" style="height:50px"></textarea>
        </div>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('obligacionModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Crear obligación</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== MODAL: EDITAR OBLIGACIÓN ===================== -->
<div class="dpr-modal-backdrop" id="editObligacionModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('editObligacionModal')">&times;</button>
    <div class="dpr-modal__title">Editar obligación</div>
    <div id="editObligacionInfo" style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST">
      <input type="hidden" name="action" value="editar_obligacion">
      <input type="hidden" name="obligation_id" id="editObligacionId">
      <div class="dpr-form-grid">
        <div class="dpr-form-group">
          <label class="dpr-label">Valor ajustado ($)</label>
          <input type="number" name="valor_ajustado" id="editObligacionValor" class="dpr-input" min="1" step="1">
          <div class="dpr-text-muted" style="font-size:11px;margin-top:4px">Déjalo vacío para volver a usar el valor calculado original.</div>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Fecha límite</label>
          <input type="date" name="fecha_limite" id="editObligacionFecha" class="dpr-input">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Motivo del ajuste</label>
          <textarea name="nota" class="dpr-textarea" style="height:50px" placeholder="Ej: fuga de agua confirmada, se ajusta consumo"></textarea>
        </div>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('editObligacionModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== MODAL: ANULAR OBLIGACIÓN ===================== -->
<div class="dpr-modal-backdrop" id="anularObligacionModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('anularObligacionModal')">&times;</button>
    <div class="dpr-modal__title">Anular obligación</div>
    <div id="anularObligacionInfo" style="background:#fff7ed;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST">
      <input type="hidden" name="action" value="anular_obligacion">
      <input type="hidden" name="obligation_id" id="anularObligacionId">
      <div class="dpr-form-group">
        <label class="dpr-label">Motivo</label>
        <textarea name="motivo" class="dpr-textarea" style="height:50px" required></textarea>
      </div>
      <label style="font-size:13px;display:flex;align-items:center;gap:6px;margin-top:8px">
        <input type="checkbox" name="force" value="1"> Forzar (ya tiene pagos aplicados, lo sé)
      </label>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('anularObligacionModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--danger">Anular</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== MODAL: NUEVO PAGO ===================== -->
<div class="dpr-modal-backdrop" id="pagoModal">
  <div class="dpr-modal" style="max-width:560px">
    <button class="dpr-modal__close" onclick="closeM('pagoModal')">&times;</button>
    <div class="dpr-modal__title">Nuevo Pago</div>
    <form method="POST" enctype="multipart/form-data" id="pagoForm">
      <input type="hidden" name="action" value="nuevo_pago">
      <div class="dpr-form-grid">
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Contrato (inquilino / unidad)</label>
          <select name="lease_id" id="pagoLeaseId" class="dpr-select" required onchange="dprCargarObligaciones(this.value)">
            <option value="">Selecciona…</option>
            <?php foreach ($leases_activos as $l): ?>
            <option value="<?= $l['id'] ?>"><?= h($l['inquilino'] . ' — ' . $l['inmueble'] . ' / ' . $l['unidad']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Monto total recibido ($)</label>
          <input type="number" name="monto_total" id="pagoMontoTotal" class="dpr-input" min="1" step="1" required oninput="dprActualizarRestante()">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Método de pago</label>
          <select name="metodo" class="dpr-select">
            <?php foreach (['transferencia','nequi','daviplata','pse','efectivo','tarjeta','otro'] as $m): ?>
            <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Referencia</label>
          <input type="text" name="referencia" class="dpr-input">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Fecha de pago</label>
          <input type="date" name="fecha_pago" class="dpr-input" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Comprobante</label>
          <input type="file" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf" class="dpr-input">
        </div>
      </div>

      <div class="dpr-divider" style="margin:14px 0"></div>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <strong style="font-size:13px">Repartir entre conceptos</strong>
        <span id="pagoRestante" class="dpr-pill dpr-pill--info">Restante: $0</span>
      </div>
      <div id="pagoConceptosWrap" style="margin-top:10px"></div>
      <button type="button" class="dpr-btn dpr-btn--sm dpr-btn--secondary" style="margin-top:6px" onclick="dprAgregarFilaConcepto()">+ Agregar concepto</button>

      <div class="dpr-form-group" style="margin-top:14px;margin-bottom:0">
        <label class="dpr-label">Nota (opcional)</label>
        <textarea name="nota" class="dpr-textarea" style="height:45px"></textarea>
      </div>

      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('pagoModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Registrar pago</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== MODAL: APROBAR PAGO DE INQUILINO ===================== -->
<div class="dpr-modal-backdrop" id="aprobarPagoModal">
  <div class="dpr-modal" style="max-width:560px">
    <button class="dpr-modal__close" onclick="closeM('aprobarPagoModal')">&times;</button>
    <div class="dpr-modal__title">Revisar pago de inquilino</div>
    <div id="aprobarPagoInfo" style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" id="aprobarPagoForm">
      <input type="hidden" name="action" value="aprobar_pago">
      <input type="hidden" name="payment_id" id="aprobarPagoId">

      <div style="display:flex;justify-content:space-between;align-items:center">
        <strong style="font-size:13px">Repartir entre conceptos</strong>
        <span id="aprobarRestante" class="dpr-pill dpr-pill--info">Restante: $0</span>
      </div>
      <div id="aprobarConceptosWrap" style="margin-top:10px"></div>
      <button type="button" class="dpr-btn dpr-btn--sm dpr-btn--secondary" style="margin-top:6px" onclick="dprAgregarFilaConcepto('aprobar')">+ Agregar concepto</button>

      <div class="dpr-form-group" style="margin-top:14px">
        <label class="dpr-label">Nota del administrador</label>
        <textarea name="nota_admin" class="dpr-textarea" style="height:45px"></textarea>
      </div>
      <div class="dpr-form-actions" style="justify-content:space-between">
        <button type="button" class="dpr-btn dpr-btn--danger" onclick="dprRechazarDesdeAprobar()">Rechazar</button>
        <div>
          <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('aprobarPagoModal')">Cerrar</button>
          <button type="submit" class="dpr-btn dpr-btn--primary">Aprobar y repartir</button>
        </div>
      </div>
    </form>
    <form method="POST" id="rechazarPagoForm" style="display:none">
      <input type="hidden" name="action" value="rechazar_pago">
      <input type="hidden" name="payment_id" id="rechazarPagoId">
      <input type="hidden" name="motivo" id="rechazarPagoMotivo">
    </form>
  </div>
</div>

<!-- ===================== MODAL: DETALLE DE PAGO ===================== -->
<div class="dpr-modal-backdrop" id="pagoDetalleModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('pagoDetalleModal')">&times;</button>
    <div class="dpr-modal__title">Detalle del pago</div>
    <div id="pagoDetalleBody">Cargando…</div>
  </div>
</div>

<!-- ===================== MODAL: VER COMPROBANTE ===================== -->
<div class="dpr-modal-backdrop" id="comprobanteModal">
  <div class="dpr-modal" style="max-width:680px">
    <button class="dpr-modal__close" onclick="closeM('comprobanteModal')">&times;</button>
    <div class="dpr-modal__title">Comprobante</div>
    <div id="comprobanteBody" style="text-align:center"></div>
    <div class="dpr-form-actions" style="justify-content:flex-end">
      <a id="comprobanteDescargar" href="#" target="_blank" class="dpr-btn dpr-btn--secondary">Abrir en pestaña nueva</a>
    </div>
  </div>
</div>

<script>
const CONCEPTOS = <?= json_encode($CONCEPTOS, JSON_UNESCAPED_UNICODE) ?>;
const AJAX_BASE = '<?= BASE_URL ?>/admin/payments_admin2.php';

function closeM(id) { document.getElementById(id).classList.remove('open'); }

// Abre el comprobante en el modal en vez de navegar a una pestaña nueva.
// Detecta por extensión si es imagen o PDF y arma el contenido apropiado.
function verComprobante(url) {
  if (!url) return;
  const body = document.getElementById('comprobanteBody');
  const esPdf = /\.pdf(\?|$)/i.test(url);
  if (esPdf) {
    body.innerHTML = '<iframe src="' + url + '" style="width:100%;height:70vh;border:0.5px solid var(--border);border-radius:8px"></iframe>';
  } else {
    body.innerHTML = '<img src="' + url + '" style="max-width:100%;max-height:70vh;border-radius:8px;object-fit:contain">';
  }
  document.getElementById('comprobanteDescargar').href = url;
  document.getElementById('comprobanteModal').classList.add('open');
}
document.querySelectorAll('.dpr-modal-backdrop').forEach(function(el) {
  el.addEventListener('click', function(e) { if (e.target === el) el.classList.remove('open'); });
});

function fmtMoney(n) {
  return '$' + Math.round(n).toLocaleString('es-CO');
}

// ---------------------------------------------------------------
// Filtros: cascada inmueble -> unidad
// ---------------------------------------------------------------
function dprLoadUnidades(propertyId, resetSelection) {
  const sel = document.getElementById('filterUnit');
  if (!propertyId) {
    fetch(AJAX_BASE + '?ajax=unidades_inmueble&property_id=0').then(r => r.json()).then(() => {});
    return; // sin inmueble seleccionado, dejamos la lista completa tal cual cargó el servidor
  }
  fetch(AJAX_BASE + '?ajax=unidades_inmueble&property_id=' + propertyId)
    .then(r => r.json())
    .then(data => {
      sel.innerHTML = '<option value="">Todas</option>';
      (data.unidades || []).forEach(u => {
        sel.innerHTML += '<option value="' + u.id + '">' + u.nombre + '</option>';
      });
    });
}

// ---------------------------------------------------------------
// Modal: Nueva Obligación
// ---------------------------------------------------------------
function openObligacionModal() {
  document.getElementById('obligacionModal').classList.add('open');
}

// ---------------------------------------------------------------
// Modal: Editar Obligación
// ---------------------------------------------------------------
function openEditObligacion(id, conceptoLabel, valorActual, fechaLimite) {
  document.getElementById('editObligacionId').value = id;
  document.getElementById('editObligacionValor').placeholder = 'Actual: ' + fmtMoney(valorActual);
  document.getElementById('editObligacionFecha').value = fechaLimite || '';
  document.getElementById('editObligacionInfo').innerHTML =
    '<strong>' + conceptoLabel + '</strong> · Valor actual: <strong>' + fmtMoney(valorActual) + '</strong>';
  document.getElementById('editObligacionModal').classList.add('open');
}

// ---------------------------------------------------------------
// Modal: Anular Obligación
// ---------------------------------------------------------------
function openAnularObligacion(id, conceptoLabel) {
  document.getElementById('anularObligacionId').value = id;
  document.getElementById('anularObligacionInfo').innerHTML =
    'Vas a anular: <strong>' + conceptoLabel + '</strong>. Si ya tiene pagos aplicados, deberás marcar "Forzar".';
  document.getElementById('anularObligacionModal').classList.add('open');
}

// ---------------------------------------------------------------
// Filas de reparto de conceptos (reutilizado en Nuevo Pago y Aprobar Pago)
// ---------------------------------------------------------------
function dprFilaConceptoHtml(prefix, obligacionesDisponibles) {
  let opcionesObl = '<option value="0">— Sin obligación (excedente / otro) —</option>';
  (obligacionesDisponibles || []).forEach(o => {
    opcionesObl += '<option value="' + o.id + '" data-concepto="' + o.concepto + '" data-pendiente="' + o.pendiente + '">'
      + o.label + ' · pendiente ' + fmtMoney(o.pendiente) + '</option>';
  });
  let opcionesConcepto = '';
  for (const k in CONCEPTOS) opcionesConcepto += '<option value="' + k + '">' + CONCEPTOS[k] + '</option>';

  return '<div class="dpr-form-grid" style="grid-template-columns:1fr 1fr 110px 30px;gap:8px;align-items:end;margin-bottom:8px">'
    + '<div class="dpr-form-group" style="margin-bottom:0">'
    +   '<label class="dpr-label" style="font-size:11px">Obligación</label>'
    +   '<select class="dpr-select dpr-select--sm" data-role="obligation_id" name="obligation_id[]" onchange="dprObligacionSeleccionada(this)">' + opcionesObl + '</select>'
    + '</div>'
    + '<div class="dpr-form-group" style="margin-bottom:0">'
    +   '<label class="dpr-label" style="font-size:11px">Concepto</label>'
    +   '<select class="dpr-select dpr-select--sm" data-role="concepto" name="concepto[]">' + opcionesConcepto + '</select>'
    + '</div>'
    + '<div class="dpr-form-group" style="margin-bottom:0">'
    +   '<label class="dpr-label" style="font-size:11px">Monto</label>'
    +   '<input type="number" class="dpr-input" data-role="monto" name="monto_concepto[]" min="1" step="1" oninput="dprActualizarRestante(\'' + prefix + '\')">'
    + '</div>'
    + '<button type="button" class="dpr-btn dpr-btn--sm dpr-btn--danger" style="height:34px" onclick="this.closest(\'div.dpr-form-grid\').remove(); dprActualizarRestante(\'' + prefix + '\')">×</button>'
    + '</div>';
}

function dprObligacionSeleccionada(sel) {
  const opt = sel.selectedOptions[0];
  const row = sel.closest('div.dpr-form-grid');
  const conceptoSel = row.querySelector('[data-role="concepto"]');
  const montoInput  = row.querySelector('[data-role="monto"]');
  if (opt && opt.dataset.concepto) {
    conceptoSel.value = opt.dataset.concepto;
    if (!montoInput.value) montoInput.value = Math.round(parseFloat(opt.dataset.pendiente || '0'));
  }
  const prefix = sel.closest('[id$="ConceptosWrap"]').id.includes('aprobar') ? 'aprobar' : 'pago';
  dprActualizarRestante(prefix);
}

let obligacionesCache = { pago: [], aprobar: [] };

function dprAgregarFilaConcepto(prefix) {
  prefix = prefix || 'pago';
  const wrap = document.getElementById(prefix + 'ConceptosWrap');
  const div = document.createElement('div');
  div.innerHTML = dprFilaConceptoHtml(prefix, obligacionesCache[prefix]);
  wrap.appendChild(div.firstElementChild);
}

function dprActualizarRestante(prefix) {
  // Esta función solo se usa para el modal "Nuevo Pago" (prefix='pago'), donde
  // el monto total SÍ es un input editable por el admin. El modal "Aprobar
  // pago de inquilino" usa dprActualizarRestanteAprobar() más abajo, porque
  // ahí el monto total es fijo (viene del pago ya recibido, no se edita).
  prefix = prefix || 'pago';
  const total = parseFloat(document.getElementById('pagoMontoTotal').value || '0') || 0;
  let asignado = 0;
  document.querySelectorAll('#' + prefix + 'ConceptosWrap [data-role="monto"]').forEach(inp => {
    asignado += parseFloat(inp.value || '0');
  });
  const restante = total - asignado;
  const badge = document.getElementById(prefix + 'Restante');
  badge.textContent = 'Restante: ' + fmtMoney(restante);
  badge.className = 'dpr-pill ' + (restante === 0 ? 'dpr-pill--ok' : (restante < 0 ? 'dpr-pill--danger' : 'dpr-pill--info'));
}

// ---------------------------------------------------------------
// Modal: Nuevo Pago
// ---------------------------------------------------------------
function openPagoModal() {
  document.getElementById('pagoForm').reset();
  document.getElementById('pagoConceptosWrap').innerHTML = '';
  obligacionesCache.pago = [];
  dprActualizarRestante('pago');
  document.getElementById('pagoModal').classList.add('open');
}

function dprCargarObligaciones(leaseId) {
  document.getElementById('pagoConceptosWrap').innerHTML = '';
  if (!leaseId) { obligacionesCache.pago = []; return; }
  fetch(AJAX_BASE + '?ajax=obligaciones_lease&lease_id=' + leaseId)
    .then(r => r.json())
    .then(data => {
      obligacionesCache.pago = data.obligaciones || [];
      // Pre-cargar una fila por cada obligación pendiente, para el caso común
      // de "pagar exactamente lo que debe" — el admin puede borrar/ajustar.
      obligacionesCache.pago.forEach(o => {
        dprAgregarFilaConcepto('pago');
        const rows = document.querySelectorAll('#pagoConceptosWrap > div');
        const lastRow = rows[rows.length - 1];
        const sel = lastRow.querySelector('[data-role="obligation_id"]');
        sel.value = o.id;
        dprObligacionSeleccionada(sel);
      });
      if (!obligacionesCache.pago.length) dprAgregarFilaConcepto('pago');
      dprActualizarRestante('pago');
    });
}

// ---------------------------------------------------------------
// Modal: Aprobar pago de inquilino
// ---------------------------------------------------------------
function openAprobarPago(paymentId, leaseId) {
  document.getElementById('aprobarPagoId').value = paymentId;
  document.getElementById('rechazarPagoId').value = paymentId;
  document.getElementById('aprobarConceptosWrap').innerHTML = '';
  obligacionesCache.aprobar = [];

  fetch(AJAX_BASE + '?ajax=pago_detalle&payment_id=' + paymentId)
    .then(r => r.json())
    .then(data => {
      if (!data.success) return;
      const p = data.pago;
      window._aprobarMontoTotal = parseFloat(p.monto_total);
      window._comprobanteUrlAprobar = p.comprobante_url || null;
      document.getElementById('aprobarPagoInfo').innerHTML =
        'Monto recibido: <strong>' + fmtMoney(p.monto_total) + '</strong> · ' + p.metodo + ' ' + (p.referencia || '')
        + (p.comprobante_url ? ' · <a href="javascript:void(0)" onclick="verComprobante(window._comprobanteUrlAprobar)">Ver comprobante</a>' : ' · <em>sin comprobante</em>')
        + (p.nota ? '<br>Nota del inquilino: ' + p.nota : '');

      return fetch(AJAX_BASE + '?ajax=obligaciones_lease&lease_id=' + leaseId);
    })
    .then(r => r.json())
    .then(data => {
      obligacionesCache.aprobar = data.obligaciones || [];
      dprAgregarFilaConcepto('aprobar');
      dprActualizarRestanteAprobar();
      document.getElementById('aprobarPagoModal').classList.add('open');
    });
}

// La función genérica dprActualizarRestante asume un input de monto total
// visible; en el modal de aprobación el total no es editable, así que
// usamos una variante puntual:
function dprActualizarRestanteAprobar() {
  let asignado = 0;
  document.querySelectorAll('#aprobarConceptosWrap [data-role="monto"]').forEach(inp => {
    asignado += parseFloat(inp.value || '0');
  });
  const restante = (window._aprobarMontoTotal || 0) - asignado;
  const badge = document.getElementById('aprobarRestante');
  badge.textContent = 'Restante: ' + fmtMoney(restante);
  badge.className = 'dpr-pill ' + (restante === 0 ? 'dpr-pill--ok' : (restante < 0 ? 'dpr-pill--danger' : 'dpr-pill--info'));
}
// Sobrescribimos el listener de montos del modal aprobar para usar el cálculo correcto
document.addEventListener('input', function(e) {
  if (e.target.closest('#aprobarConceptosWrap')) dprActualizarRestanteAprobar();
});

function dprRechazarDesdeAprobar() {
  const motivo = prompt('Motivo del rechazo (el inquilino lo verá):', 'Comprobante ilegible o monto no coincide.');
  if (motivo === null) return;
  document.getElementById('rechazarPagoMotivo').value = motivo;
  document.getElementById('rechazarPagoForm').submit();
}

// ---------------------------------------------------------------
// Modal: Detalle de pago aprobado
// ---------------------------------------------------------------
function openPagoDetalle(paymentId) {
  document.getElementById('pagoDetalleBody').innerHTML = 'Cargando…';
  document.getElementById('pagoDetalleModal').classList.add('open');
  fetch(AJAX_BASE + '?ajax=pago_detalle&payment_id=' + paymentId)
    .then(r => r.json())
    .then(data => {
      if (!data.success) { document.getElementById('pagoDetalleBody').innerHTML = 'No encontrado.'; return; }
      const p = data.pago;
      window._comprobanteUrlDetalle = p.comprobante_url || null;
      let html = '<div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px">'
        + 'Monto total: <strong>' + fmtMoney(p.monto_total) + '</strong> · ' + p.metodo + ' ' + (p.referencia || '') + '<br>'
        + 'Fecha: ' + p.fecha_pago
        + (p.comprobante_url ? ' · <a href="javascript:void(0)" onclick="verComprobante(window._comprobanteUrlDetalle)">Ver comprobante</a>' : '')
        + '</div>';
      html += '<table class="dpr-table"><thead><tr><th>Concepto</th><th>Periodo</th><th>Monto</th><th></th></tr></thead><tbody>';
      (data.aplicaciones || []).forEach(a => {
        html += '<tr><td>' + (CONCEPTOS[a.concepto] || a.concepto) + (a.obligation_id ? '' : ' <span class="dpr-pill dpr-pill--warn" style="font-size:10px">sin asignar</span>') + '</td>'
          + '<td>' + (a.obl_periodo || '—') + '</td>'
          + '<td><strong>' + fmtMoney(parseFloat(a.monto)) + '</strong></td>'
          + '<td><button type="button" class="dpr-btn dpr-btn--sm dpr-btn--danger" onclick="dprEliminarAplicacion(' + a.id + ')">Quitar</button></td></tr>';
      });
      html += '</tbody></table>';
      document.getElementById('pagoDetalleBody').innerHTML = html;
    });
}

function dprEliminarAplicacion(applicationId) {
  const motivo = prompt('Motivo (queda en auditoría):', '');
  if (motivo === null) return;
  const f = document.createElement('form');
  f.method = 'POST';
  f.innerHTML = '<input type="hidden" name="action" value="eliminar_aplicacion">'
    + '<input type="hidden" name="application_id" value="' + applicationId + '">'
    + '<input type="hidden" name="motivo" value="' + motivo.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(f);
  f.submit();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
