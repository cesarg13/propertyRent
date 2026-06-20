<?php
// =============================================================
//  PropertyRent — Admin: Gestión de Inmuebles
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$user        = dpr_current_user();
$action      = $_GET['action']  ?? 'list';
$property_id = (int)($_GET['id'] ?? 0);
$msg = $type = '';

// ---------------------------------------------------------------
// POST: crear / editar / eliminar
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $pid    = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $tipo   = $_POST['tipo']   ?? 'edificio';
        $dir    = trim($_POST['direccion'] ?? '');
        $ciudad = trim($_POST['ciudad']    ?? '');
        $depto  = trim($_POST['departamento'] ?? '');
        $desc   = trim($_POST['descripcion']  ?? '');

        if (!$nombre || !$dir || !$ciudad) {
            $msg = 'Nombre, dirección y ciudad son obligatorios.'; $type = 'danger';
        } else {
            if ($pid) {
                $r = db_execute($mysqli,
                    "UPDATE properties SET nombre=?,tipo=?,direccion=?,ciudad=?,departamento=?,descripcion=?,updated_at=NOW() WHERE id=?",
                    'ssssssi', [$nombre,$tipo,$dir,$ciudad,$depto,$desc,$pid]);
                dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'properties', $pid, "Editó: $nombre");
                $msg = 'Inmueble actualizado correctamente.'; $type = 'success';
            } else {
                $r = db_execute($mysqli,
                    "INSERT INTO properties (nombre,tipo,direccion,ciudad,departamento,descripcion,admin_id) VALUES (?,?,?,?,?,?,?)",
                    'ssssssi', [$nombre,$tipo,$dir,$ciudad,$depto,$desc,$user['id']]);
                dpr_audit_log($mysqli, $user['id'], 'INSERT', 'properties', $r['insert_id'], "Creó: $nombre");
                $msg = 'Inmueble creado correctamente.'; $type = 'success';
            }
        }
        $action = 'list';
    }

    if ($act === 'delete') {
        $pid = (int)($_POST['id'] ?? 0);
        // Verificar que no tenga unidades activas
        $tiene = db_query($mysqli, "SELECT COUNT(*) AS n FROM units WHERE property_id=? AND estado!='inactiva'", 'i', [$pid]);
        if ((int)$tiene[0]['n'] > 0) {
            $msg = 'No se puede eliminar: el inmueble tiene unidades activas.'; $type = 'danger';
        } else {
            db_execute($mysqli, "UPDATE properties SET estado='inactivo' WHERE id=?", 'i', [$pid]);
            dpr_audit_log($mysqli, $user['id'], 'DELETE', 'properties', $pid, 'Desactivó inmueble');
            $msg = 'Inmueble desactivado.'; $type = 'success';
        }
        $action = 'list';
    }
}

// ---------------------------------------------------------------
// GET datos para editar
// ---------------------------------------------------------------
$property = [];
if ($action === 'edit' && $property_id) {
    $rows = db_query($mysqli, "SELECT * FROM properties WHERE id=?", 'i', [$property_id]);
    $property = $rows[0] ?? [];
    if (!$property) { $action = 'list'; }
}

// ---------------------------------------------------------------
// Listado
// ---------------------------------------------------------------
$properties = db_query($mysqli,
    "SELECT p.*, COUNT(u.id) AS total_units,
            SUM(CASE WHEN u.estado='ocupada' THEN 1 ELSE 0 END) AS ocupadas
     FROM properties p
     LEFT JOIN units u ON u.property_id = p.id AND u.estado != 'inactiva'
     WHERE p.estado = 'activo'
     GROUP BY p.id
     ORDER BY p.nombre ASC");

$page_title  = 'Inmuebles';
$active_menu = 'properties';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<!-- ========================= FORM (new / edit) ========================= -->
<?php if ($action === 'new' || $action === 'edit'): ?>
<div class="dpr-card">
  <div class="dpr-card__title"><?= $action === 'edit' ? 'Editar inmueble' : 'Nuevo inmueble' ?></div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id"     value="<?= (int)($property['id'] ?? 0) ?>">
    <div class="dpr-form-grid">
      <div class="dpr-form-group">
        <label class="dpr-label">Nombre del inmueble *</label>
        <input type="text" name="nombre" class="dpr-input" required value="<?= h($property['nombre'] ?? '') ?>" placeholder="Ej: Edificio Prado">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Tipo *</label>
        <select name="tipo" class="dpr-select">
          <?php foreach (['edificio','casa','centro_comercial','otro'] as $t): ?>
          <option value="<?= $t ?>" <?= ($property['tipo'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$t)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group dpr-form-group--full">
        <label class="dpr-label">Dirección *</label>
        <input type="text" name="direccion" class="dpr-input" required value="<?= h($property['direccion'] ?? '') ?>" placeholder="Cra 15 # 80-30">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Ciudad *</label>
        <input type="text" name="ciudad" class="dpr-input" required value="<?= h($property['ciudad'] ?? '') ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Departamento</label>
        <input type="text" name="departamento" class="dpr-input" value="<?= h($property['departamento'] ?? '') ?>">
      </div>
      <div class="dpr-form-group dpr-form-group--full">
        <label class="dpr-label">Descripción / Notas</label>
        <textarea name="descripcion" class="dpr-textarea"><?= h($property['descripcion'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="dpr-form-actions">
      <a href="properties.php" class="dpr-btn dpr-btn--secondary">Cancelar</a>
      <button type="submit" class="dpr-btn dpr-btn--primary">Guardar inmueble</button>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ========================= LIST ========================= -->
<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Inmuebles</div>
    <div class="dpr-page-sub"><?= count($properties) ?> propiedades registradas</div>
  </div>
  <a href="?action=new" class="dpr-btn dpr-btn--primary">+ Nuevo inmueble</a>
</div>

<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr>
          <th>Nombre</th><th>Tipo</th><th>Ciudad</th>
          <th>Unidades</th><th>Ocupación</th><th>Estado</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$properties): ?>
        <tr><td colspan="7" class="dpr-text-muted" style="text-align:center;padding:24px">No hay inmuebles registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($properties as $p):
          $pct = $p['total_units'] > 0 ? round($p['ocupadas']/$p['total_units']*100) : 0;
        ?>
        <tr>
          <td><strong><?= h($p['nombre']) ?></strong></td>
          <td><?= ucfirst(str_replace('_',' ',$p['tipo'])) ?></td>
          <td><?= h($p['ciudad']) ?>, <?= h($p['departamento']) ?></td>
          <td><?= (int)$p['total_units'] ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;background:#e2e8f0;border-radius:4px;height:6px">
                <div style="width:<?= $pct ?>%;background:var(--blue);height:6px;border-radius:4px"></div>
              </div>
              <span style="font-size:12px;min-width:34px"><?= $pct ?>%</span>
            </div>
          </td>
          <td><span class="dpr-pill dpr-pill--ok"><?= h($p['estado']) ?></span></td>
          <td class="dpr-flex">
            <a href="units.php?property_id=<?= $p['id'] ?>" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Unidades</a>
            <a href="?action=edit&id=<?= $p['id'] ?>" class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Editar">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 2.5l2 2L4 12H2v-2L9.5 2.5Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
            </a>
            <form method="POST" style="display:inline" onsubmit="return confirm('¿Desactivar este inmueble?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id"     value="<?= $p['id'] ?>">
              <button class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Desactivar">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 4h10M5 4V2h4v2M6 7v4M8 7v4M3 4l1 8h6l1-8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
