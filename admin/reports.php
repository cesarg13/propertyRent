<?php
// =============================================================
//  PropertyRent — Admin: Reportes y Consultas
//  Vistas: por inmueble, por unidad, arriendos, servicios,
//          cartera, recaudación mensual, estado general
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$report  = $_GET['report']      ?? 'recaudacion';
$f_prop  = (int)($_GET['property_id'] ?? 0);
$f_desde = $_GET['desde']       ?? date('Y-m', strtotime('-5 months'));
$f_hasta = $_GET['hasta']       ?? date('Y-m');
$all_props = db_query($mysqli, "SELECT id, nombre FROM properties WHERE estado='activo' ORDER BY nombre");

// ---------------------------------------------------------------
// Datos según reporte
// ---------------------------------------------------------------
$rows = [];
$cols = [];

if ($report === 'recaudacion') {
    $cols = ['Periodo','Recaudado','Por cobrar','En mora','Unidades morosas','Comprobantes'];
    $prop_filter = $f_prop ? "AND pr.id=$f_prop" : '';
    $rows = db_query($mysqli,
        "SELECT p.periodo,
                SUM(CASE WHEN p.estado='pagado'    THEN p.valor_total ELSE 0 END) AS recaudado,
                SUM(CASE WHEN p.estado='pendiente' THEN p.valor_arriendo ELSE 0 END) AS por_cobrar,
                SUM(CASE WHEN p.estado='moroso'    THEN p.valor_total ELSE 0 END) AS en_mora,
                COUNT(CASE WHEN p.estado='moroso'  THEN 1 END) AS unidades_morosas,
                COUNT(CASE WHEN p.estado='validando' THEN 1 END) AS comprobantes
         FROM payments p
         JOIN leases l ON l.id=p.lease_id
         JOIN units un ON un.id=l.unit_id
         JOIN properties pr ON pr.id=un.property_id
         WHERE p.periodo BETWEEN '$f_desde' AND '$f_hasta' $prop_filter
         GROUP BY p.periodo ORDER BY p.periodo DESC");
}

if ($report === 'cartera') {
    $cols = ['Inquilino','Inmueble/Unidad','Meses mora','Total adeudado','Último pago'];
    $rows = db_query($mysqli,
        "SELECT CONCAT(u.nombre,' ',u.apellido) AS inquilino,
                CONCAT(pr.nombre,'/',un.nombre) AS unidad,
                COUNT(p.id) AS meses_mora,
                SUM(p.valor_total) AS total,
                MAX(p2.fecha_pago) AS ultimo_pago
         FROM payments p
         JOIN leases l  ON l.id=p.lease_id
         JOIN users u   ON u.id=l.tenant_id
         JOIN units un  ON un.id=l.unit_id
         JOIN properties pr ON pr.id=un.property_id
         LEFT JOIN payments p2 ON p2.lease_id=l.id AND p2.estado='pagado'
         WHERE p.estado='moroso'" . ($f_prop ? " AND pr.id=$f_prop" : '') .
        " GROUP BY l.id ORDER BY total DESC");
}

if ($report === 'servicios') {
    $cols = ['Periodo','Inmueble','Unidad','Inquilino','Tipo','Valor','Estado'];
    $prop_filter = $f_prop ? "AND pr.id=$f_prop" : '';
    $rows = db_query($mysqli,
        "SELECT ps.periodo, pr.nombre AS inmueble, un.nombre AS unidad,
                CONCAT(u.nombre,' ',u.apellido) AS inquilino,
                ps.tipo, ps.valor, ps.estado
         FROM public_services ps
         JOIN units un ON un.id=ps.unit_id
         JOIN properties pr ON pr.id=un.property_id
         JOIN leases l ON l.id=ps.lease_id
         JOIN users u ON u.id=l.tenant_id
         WHERE ps.periodo BETWEEN '$f_desde' AND '$f_hasta' $prop_filter
         ORDER BY ps.periodo DESC, pr.nombre, un.nombre");
}

if ($report === 'inmueble') {
    $cols = ['Inmueble','Tipo','Ciudad','Unidades','Ocupadas','Recaudado (mes)','En mora'];
    $periodo = date('Y-m');
    $rows = db_query($mysqli,
        "SELECT pr.nombre, pr.tipo, pr.ciudad,
                COUNT(DISTINCT un.id) AS total_units,
                SUM(CASE WHEN un.estado='ocupada' THEN 1 ELSE 0 END) AS ocupadas,
                COALESCE(SUM(CASE WHEN p.estado='pagado' AND p.periodo='$periodo' THEN p.valor_total END),0) AS recaudado,
                COALESCE(SUM(CASE WHEN p.estado='moroso' THEN p.valor_total END),0) AS mora
         FROM properties pr
         LEFT JOIN units un ON un.property_id=pr.id AND un.estado!='inactiva'
         LEFT JOIN leases l ON l.unit_id=un.id AND l.estado='activo'
         LEFT JOIN payments p ON p.lease_id=l.id
         WHERE pr.estado='activo'
         GROUP BY pr.id ORDER BY pr.nombre");
}

if ($report === 'contratos') {
    $cols = ['Inquilino','Cédula','Inmueble','Unidad','Inicio','Fin','Valor','Estado'];
    $prop_filter = $f_prop ? "AND pr.id=$f_prop" : '';
    $rows = db_query($mysqli,
        "SELECT CONCAT(u.nombre,' ',u.apellido) AS inquilino, u.cedula,
                pr.nombre AS inmueble, un.nombre AS unidad,
                l.fecha_inicio, l.fecha_fin, l.valor_mensual, l.estado
         FROM leases l
         JOIN users u ON u.id=l.tenant_id
         JOIN units un ON un.id=l.unit_id
         JOIN properties pr ON pr.id=un.property_id
         WHERE 1=1 $prop_filter
         ORDER BY l.estado DESC, pr.nombre, un.nombre");
}

$page_title  = 'Reportes';
$active_menu = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Reportes y consultas</div>
    <div class="dpr-page-sub">Información completa del sistema de arrendamientos</div>
  </div>
</div>

<!-- Filtros -->
<form method="GET" class="dpr-card" style="padding:14px 18px;margin-bottom:16px">
  <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
    <div class="dpr-form-group" style="flex:0 0 auto">
      <label class="dpr-label">Tipo de reporte</label>
      <select name="report" class="dpr-select" onchange="this.form.submit()">
        <?php foreach ([
          'recaudacion' => 'Recaudación mensual',
          'cartera'     => 'Cartera en mora',
          'servicios'   => 'Servicios públicos',
          'inmueble'    => 'Por inmueble',
          'contratos'   => 'Contratos activos',
        ] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $report===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (in_array($report,['recaudacion','servicios','contratos'])): ?>
    <div class="dpr-form-group">
      <label class="dpr-label">Desde (periodo)</label>
      <input type="month" name="desde" class="dpr-input" value="<?= h($f_desde) ?>">
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Hasta (periodo)</label>
      <input type="month" name="hasta" class="dpr-input" value="<?= h($f_hasta) ?>">
    </div>
    <?php endif; ?>
    <div class="dpr-form-group">
      <label class="dpr-label">Inmueble</label>
      <select name="property_id" class="dpr-select">
        <option value="">Todos</option>
        <?php foreach ($all_props as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $f_prop==$p['id']?'selected':'' ?>><?= h($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="dpr-btn dpr-btn--primary">Consultar</button>
  </div>
</form>

<!-- Resultado -->
<div class="dpr-card">
  <div class="dpr-card__title">
    <?= match($report) {
      'recaudacion' => 'Recaudación mensual',
      'cartera'     => 'Cartera en mora',
      'servicios'   => 'Servicios públicos',
      'inmueble'    => 'Resumen por inmueble',
      'contratos'   => 'Contratos de arrendamiento',
    } ?>
    <span class="dpr-text-muted" style="font-weight:400;font-size:13px;margin-left:8px"><?= count($rows) ?> registros</span>
  </div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead><tr><?php foreach ($cols as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="<?= count($cols) ?>" class="dpr-text-muted" style="text-align:center;padding:24px">Sin resultados para los filtros aplicados.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row):
          $vals = array_values($row);
        ?>
        <tr>
          <?php foreach ($vals as $i=>$v):
            // Dar formato especial a montos y estados
            $col = strtolower($cols[$i] ?? '');
            if (str_contains($col,'recauda') || str_contains($col,'cobrar') || str_contains($col,'mora') || str_contains($col,'valor') || str_contains($col,'adeuda')) {
              echo '<td>'.fmt_money((float)($v??0)).'</td>';
            } elseif ($col === 'estado') {
              $pill = match($v) { 'activo'=>'ok','pagado'=>'ok','moroso'=>'danger','pendiente'=>'warn','terminado'=>'neutral', default=>'neutral' };
              echo "<td><span class='dpr-pill dpr-pill--$pill'>".h($v).'</span></td>';
            } elseif ($col === 'inicio' || $col === 'fin' || str_contains($col,'pago')) {
              echo '<td>'.($v ? fmt_date($v) : '<span class="dpr-text-muted">—</span>').'</td>';
            } else {
              echo '<td>'.h($v ?? '—').'</td>';
            }
          endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
