<?php
// =============================================================
//  PropertyRent — Header HTML
//  Variables esperadas antes del include:
//    $page_title (string) — título de la página actual
//    $active_menu (string) — ítem activo del sidebar
// =============================================================
$user = dpr_current_user();
$is_admin = ($user['rol'] === ROL_ADMIN);
$base = BASE_URL;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($page_title ?? APP_NAME) ?> — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="<?= $base ?>/assets/css/main.css">
  <?php if ($is_admin): ?>
  <link rel="stylesheet" href="<?= $base ?>/assets/css/admin.css">
  <?php endif; ?>
</head>
<body>
<div class="dpr-layout">

  <!-- ===== SIDEBAR ===== -->
  <aside class="dpr-sidebar">
    <div class="dpr-sidebar__logo">
      <span class="dpr-sidebar__logo-icon">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
          <path d="M2 18V8L10 2l8 6v10H13v-6H7v6H2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        </svg>
      </span>
      <div>
        <div class="dpr-sidebar__app-name"><?= APP_NAME ?></div>
        <div class="dpr-sidebar__role"><?= $is_admin ? 'Administrador' : 'Inquilino' ?></div>
      </div>
    </div>

    <nav class="dpr-sidebar__nav">
      <?php if ($is_admin): ?>
        <div class="dpr-sidebar__section">Principal</div>
        <?php dpr_nav_item('dashboard',   'Dashboard',     "$base/admin/dashboard.php",   $active_menu ?? ''); ?>
        <div class="dpr-sidebar__section">Gestión</div>
        <?php dpr_nav_item('properties',  'Inmuebles',     "$base/admin/properties.php",  $active_menu ?? ''); ?>
        <?php dpr_nav_item('units',       'Unidades',      "$base/admin/units.php",       $active_menu ?? ''); ?>
        <?php dpr_nav_item('tenants',     'Inquilinos',    "$base/admin/tenants.php",     $active_menu ?? ''); ?>
        <?php dpr_nav_item('leases',      'Contratos',     "$base/admin/leases.php",      $active_menu ?? ''); ?>
        <div class="dpr-sidebar__section">Finanzas</div>
        <?php dpr_nav_item('payments',    'Pagos',         "$base/admin/payments_admin.php", $active_menu ?? ''); ?>
        <?php dpr_nav_item('services',    'Serv. Públicos',"$base/admin/services.php",   $active_menu ?? ''); ?>
        <?php dpr_nav_item('meters',      'Medidores',     "$base/admin/meters.php",      $active_menu ?? ''); ?>
        <div class="dpr-sidebar__section">Sistema</div>
        <?php dpr_nav_item('documents',    'Documentos',    "$base/admin/documents.php",   $active_menu ?? ''); ?>
        <?php dpr_nav_item('mora',        'Config. Mora',  "$base/admin/mora_config.php", $active_menu ?? ''); ?>
        <?php dpr_nav_item('reports',     'Reportes',      "$base/admin/reports.php",     $active_menu ?? ''); ?>
        <?php dpr_nav_item('audit',       'Auditoría',     "$base/admin/audit_log.php",   $active_menu ?? ''); ?>
      <?php else: ?>
        <div class="dpr-sidebar__section">Mi cuenta</div>
        <?php dpr_nav_item('dashboard',   'Dashboard',     "$base/tenant/dashboard.php",  $active_menu ?? ''); ?>
        <?php dpr_nav_item('payments',    'Mis Pagos',     "$base/tenant/payments.php",   $active_menu ?? ''); ?>
        <?php dpr_nav_item('services',    'Serv. Públicos',"$base/tenant/services.php",  $active_menu ?? ''); ?>
        <?php dpr_nav_item('documents',   'Documentos',    "$base/tenant/documents.php",  $active_menu ?? ''); ?>
        <?php dpr_nav_item('support',     'Soporte',       "$base/tenant/support.php",    $active_menu ?? ''); ?>
      <?php endif; ?>
    </nav>

    <div class="dpr-sidebar__user">
      <div class="dpr-avatar"><?= strtoupper(substr($user['nombre'], 0, 2)) ?></div>
      <div class="dpr-sidebar__user-info">
        <div class="dpr-sidebar__user-name"><?= h($user['nombre']) ?></div>
        <div class="dpr-sidebar__user-email"><?= h($user['email']) ?></div>
      </div>
      <a href="<?= $base ?>/logout.php" class="dpr-sidebar__logout" title="Cerrar sesión">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M6 2H3a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3M11 11l3-3-3-3M14 8H6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </a>
    </div>
  </aside>

  <!-- ===== MAIN ===== -->
  <main class="dpr-main">
    <?php if (!empty($_GET['msg'])): ?>
    <div class="dpr-alert dpr-alert--<?= h($_GET['type'] ?? 'info') ?>">
      <?= h($_GET['msg']) ?>
    </div>
    <?php endif; ?>

<?php
function dpr_nav_item(string $key, string $label, string $url, string $active): void {
    $cls = $key === $active ? ' dpr-sidebar__nav-item--active' : '';
    echo "<a href=\"$url\" class=\"dpr-sidebar__nav-item$cls\">$label</a>";
}
?>
