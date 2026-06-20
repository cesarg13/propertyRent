<?php
// =============================================================
//  PropertyRent — Inquilino: Documentos
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user = dpr_current_user();

$lease = db_query($mysqli,
    "SELECT l.id FROM leases l WHERE l.tenant_id=? AND l.estado='activo' LIMIT 1",
    'i', [$user['id']]);
$lease_id = $lease[0]['id'] ?? null;

$docs = $lease_id ? db_query($mysqli,
    "SELECT * FROM documents WHERE (tenant_id=? OR lease_id=?) ORDER BY fecha_doc DESC",
    'ii', [$user['id'], $lease_id]) : [];

$page_title  = 'Documentos';
$active_menu = 'documents';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Mis documentos</div>
    <div class="dpr-page-sub">Contratos, inventarios y certificados</div>
  </div>
</div>

<div class="dpr-card">
  <?php if (!$docs): ?>
  <div class="dpr-text-muted" style="text-align:center;padding:32px">
    No hay documentos disponibles. El administrador los irá subiendo conforme se generen.
  </div>
  <?php else: ?>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead><tr><th>Documento</th><th>Tipo</th><th>Fecha</th><th>Acción</th></tr></thead>
      <tbody>
        <?php foreach ($docs as $d):
          $tipo_labels = [
            'contrato'    => 'Contrato',
            'inventario'  => 'Inventario',
            'paz_y_salvo' => 'Paz y salvo',
            'cedula'      => 'Documento ID',
            'reglamento'  => 'Reglamento',
            'otro'        => 'Otro',
          ];
        ?>
        <tr>
          <td><?= h($d['nombre']) ?></td>
          <td><span class="dpr-pill dpr-pill--info"><?= h($tipo_labels[$d['tipo']] ?? $d['tipo']) ?></span></td>
          <td><?= $d['fecha_doc'] ? fmt_date($d['fecha_doc']) : '—' ?></td>
          <td>
            <a href="<?= h($d['archivo_url']) ?>" target="_blank" class="dpr-btn dpr-btn--sm dpr-btn--primary">
              Descargar
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
