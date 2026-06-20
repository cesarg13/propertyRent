<?php
// =============================================================
//  PropertyRent — Admin Dashboard
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$page_title  = 'Dashboard';
$active_menu = 'dashboard';
$kpis = dpr_kpis($mysqli);

// Ingresos vs gastos últimos 6 meses
$ingresos = db_query($mysqli,
    "SELECT periodo, SUM(valor_total) AS total
     FROM payments WHERE estado='pagado'
     GROUP BY periodo ORDER BY periodo DESC LIMIT 6");
$gastos = db_query($mysqli,
    "SELECT DATE_FORMAT(fecha,'%Y-%m') AS periodo, SUM(valor) AS total
     FROM expenses GROUP BY periodo ORDER BY periodo DESC LIMIT 6");

// Comprobantes pendientes
$pendientes = db_query($mysqli,
    "SELECT p.id, p.periodo, p.valor_total, p.comprobante_url, p.nota_inquilino,
            p.created_at,
            CONCAT(u.nombre,' ',u.apellido) AS inquilino,
            un.nombre AS unidad, pr.nombre AS inmueble
     FROM payments p
     JOIN leases l   ON l.id = p.lease_id
     JOIN users u    ON u.id = l.tenant_id
     JOIN units un   ON un.id = l.unit_id
     JOIN properties pr ON pr.id = un.property_id
     WHERE p.estado = 'validando'
     ORDER BY p.created_at ASC");

// Mora activa
$mora_activa = db_query($mysqli,
    "SELECT p.id, p.periodo, p.valor_arriendo, p.valor_mora, p.valor_total,
            p.fecha_vencimiento, DATEDIFF(CURDATE(), p.fecha_vencimiento) AS dias,
            CONCAT(u.nombre,' ',u.apellido) AS inquilino,
            un.nombre AS unidad, pr.nombre AS inmueble
     FROM payments p
     JOIN leases l   ON l.id = p.lease_id
     JOIN users u    ON u.id = l.tenant_id
     JOIN units un   ON un.id = l.unit_id
     JOIN properties pr ON pr.id = un.property_id
     WHERE p.estado = 'moroso'
     ORDER BY p.fecha_vencimiento ASC
     LIMIT 20");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Dashboard</div>
    <div class="dpr-page-sub">Periodo: <?= date('F Y') ?> · <?= date('d M Y') ?></div>
  </div>
</div>

<!-- KPIs -->
<div class="dpr-kpi-grid">
  <div class="dpr-kpi dpr-kpi--success">
    <div class="dpr-kpi__label">Recaudación del mes</div>
    <div class="dpr-kpi__value"><?= fmt_money($kpis['recaudado']) ?></div>
    <div class="dpr-kpi__sub">Pagos validados · <?= $kpis['periodo'] ?></div>
  </div>
  <div class="dpr-kpi">
    <div class="dpr-kpi__label">Ocupación</div>
    <div class="dpr-kpi__value"><?= $kpis['ocupacion'] ?>%</div>
    <div class="dpr-kpi__sub"><?= $kpis['ocupadas'] ?> de <?= $kpis['total_units'] ?> unidades</div>
  </div>
  <div class="dpr-kpi dpr-kpi--danger">
    <div class="dpr-kpi__label">Unidades en mora</div>
    <div class="dpr-kpi__value"><?= $kpis['morosos'] ?></div>
    <div class="dpr-kpi__sub">Contratos activos vencidos</div>
  </div>
  <div class="dpr-kpi dpr-kpi--warn">
    <div class="dpr-kpi__label">Por validar</div>
    <div class="dpr-kpi__value"><?= $kpis['validando'] ?></div>
    <div class="dpr-kpi__sub">Comprobantes subidos</div>
  </div>
</div>

<?php if ($pendientes): ?>
<!-- Comprobantes pendientes de validación -->
<div class="dpr-card">
  <div class="dpr-card__title">Comprobantes pendientes de validar
    <span class="dpr-pill dpr-pill--warn" style="margin-left:8px"><?= count($pendientes) ?></span>
  </div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr>
          <th>Inquilino</th><th>Inmueble / Unidad</th><th>Periodo</th>
          <th>Valor</th><th>Enviado</th><th>Comprobante</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendientes as $p): ?>
        <tr>
          <td><?= h($p['inquilino']) ?></td>
          <td><?= h($p['inmueble']) ?> / <?= h($p['unidad']) ?></td>
          <td><?= h($p['periodo']) ?></td>
          <td><?= fmt_money($p['valor_total']) ?></td>
          <td><?= fmt_date($p['created_at']) ?></td>
          <td>
            <?php if ($p['comprobante_url']): ?>
              <a href="<?= h($p['comprobante_url']) ?>" target="_blank" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Ver archivo</a>
            <?php else: ?>
              <span class="dpr-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="dpr-flex">
            <form method="POST" action="payments_admin.php" style="display:inline">
              <input type="hidden" name="action"     value="validate">
              <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
              <button class="dpr-btn dpr-btn--sm dpr-btn--primary" onclick="return confirm('¿Aprobar este pago?')">Aprobar</button>
            </form>
            <form method="POST" action="payments_admin.php" style="display:inline">
              <input type="hidden" name="action"     value="reject">
              <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
              <button class="dpr-btn dpr-btn--sm dpr-btn--danger" onclick="return confirm('¿Rechazar este comprobante?')">Rechazar</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Mora activa -->
<?php if ($mora_activa): ?>
<div class="dpr-card">
  <div class="dpr-card__title">Cartera en mora</div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr>
          <th>Inquilino</th><th>Inmueble / Unidad</th><th>Periodo</th>
          <th>Arriendo</th><th>Mora</th><th>Total</th><th>Días vencido</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($mora_activa as $m): ?>
        <tr>
          <td><?= h($m['inquilino']) ?></td>
          <td><?= h($m['inmueble']) ?> / <?= h($m['unidad']) ?></td>
          <td><?= h($m['periodo']) ?></td>
          <td><?= fmt_money($m['valor_arriendo']) ?></td>
          <td style="color:#dc2626"><?= fmt_money($m['valor_mora']) ?></td>
          <td style="font-weight:600"><?= fmt_money($m['valor_total']) ?></td>
          <td><span class="dpr-pill dpr-pill--danger"><?= (int)$m['dias'] ?> días</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Accesos rápidos -->
<div class="dpr-grid-3">
  <a href="properties.php?action=new" class="dpr-btn dpr-btn--secondary">+ Nuevo inmueble</a>
  <a href="tenants.php?action=new"    class="dpr-btn dpr-btn--secondary">+ Nuevo inquilino</a>
  <a href="payments_admin.php?action=new" class="dpr-btn dpr-btn--secondary">+ Registrar pago</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
