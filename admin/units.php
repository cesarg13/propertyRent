<?php
// =============================================================
//  PropertyRent — Admin: Gestión de Unidades
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$user        = dpr_current_user();
$action      = $_GET['action']      ?? 'list';
$unit_id     = (int)($_GET['id']    ?? 0);
$filter_prop = (int)($_GET['property_id'] ?? 0);
$msg = $type = '';

// ---------------------------------------------------------------
// POST: guardar / eliminar
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	
	error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));


    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $uid          = (int)($_POST['id']            ?? 0);
        $property_id  = (int)($_POST['property_id']   ?? 0);
        $nombre       = trim($_POST['nombre']         ?? '');
        $tipo         = $_POST['tipo']                ?? 'apartamento';
        $piso         = $_POST['piso']   !== ''       ? (int)$_POST['piso']   : null;
        $area         = $_POST['area_m2'] !== ''      ? (float)$_POST['area_m2'] : null;
        $valor        = (float)($_POST['valor_arriendo'] ?? 0);
        $estado       = $_POST['estado']              ?? 'disponible';
        $ocupante_tipo = $_POST['ocupante_tipo']       ?? 'inquilino';
        $desc         = trim($_POST['descripcion']    ?? '');
        $publicar     = isset($_POST['publicar']) ? 1 : 0;
        $video_embed  = trim($_POST['video_embed'] ?? '');

        // Coherencia estado <-> ocupante_tipo
        if ($estado !== 'ocupada') {
            $ocupante_tipo = 'vacante';
        } elseif ($ocupante_tipo === 'vacante') {
            $ocupante_tipo = 'inquilino';
        }

        if (!$property_id || !$nombre || $valor <= 0) {
            $msg = 'Inmueble, nombre y valor son obligatorios.'; $type = 'danger';
        } else {
			// Handle image upload (aplica tanto a crear como a editar)
			$imagen_url = null;
			if (!empty($_FILES['imagen']['name'])) {
				$up = dpr_upload_image($_FILES['imagen'], '/unidades');
				if ($up['success']) $imagen_url = $up['url'];
			}

			if ($uid) {
				// ---- EDITAR ----
				if ($imagen_url) {
					$sql = "UPDATE units SET property_id=?, nombre=?, tipo=?, piso=?, area_m2=?, valor_arriendo=?, estado=?, ocupante_tipo=?, descripcion=?, publicar=?, video_embed=?, imagen_url=?, updated_at=NOW() WHERE id=?";
					$types  = 'issiddssssssi'; // 12 parámetros + id
					$params = [$property_id, $nombre, $tipo, $piso, $area, $valor, $estado, $ocupante_tipo, $desc, $publicar, $video_embed, $imagen_url, $uid];
				} else {
					$sql = "UPDATE units SET property_id=?, nombre=?, tipo=?, piso=?, area_m2=?, valor_arriendo=?, estado=?, ocupante_tipo=?, descripcion=?, publicar=?, video_embed=?, updated_at=NOW() WHERE id=?";
					$types  = 'issiddsssssi'; // 11 parámetros + id
					$params = [$property_id, $nombre, $tipo, $piso, $area, $valor, $estado, $ocupante_tipo, $desc, $publicar, $video_embed, $uid];
				}

				db_execute($mysqli, $sql, $types, $params);

				dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'units', $uid, "Editó unidad: $nombre (ocupante_tipo=$ocupante_tipo)");

				$msg = 'Unidad actualizada.'; $type = 'success';
			} else {
				// ---- CREAR ----
				$sql = "INSERT INTO units (property_id, nombre, tipo, piso, area_m2, valor_arriendo, estado, ocupante_tipo, descripcion, publicar, video_embed, imagen_url, created_at, updated_at)
				        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
				$types  = 'issiddssssss'; // 12 parámetros
				$params = [$property_id, $nombre, $tipo, $piso, $area, $valor, $estado, $ocupante_tipo, $desc, $publicar, $video_embed, $imagen_url];

				$r = db_execute($mysqli, $sql, $types, $params);

				dpr_audit_log($mysqli, $user['id'], 'INSERT', 'units', $r['insert_id'], "Creó unidad: $nombre (ocupante_tipo=$ocupante_tipo)");

				$msg = 'Unidad creada correctamente.'; $type = 'success';
			}
        }
        $action = 'list';
    }

    if ($act === 'delete') {
        $uid = (int)($_POST['id'] ?? 0);
        $tiene = db_query($mysqli, "SELECT COUNT(*) AS n FROM leases WHERE unit_id=? AND estado='activo'", 'i', [$uid]);
        if ((int)$tiene[0]['n'] > 0) {
            $msg = 'No se puede eliminar: la unidad tiene un contrato activo.'; $type = 'danger';
        } else {
            db_execute($mysqli, "UPDATE units SET estado='inactiva' WHERE id=?", 'i', [$uid]);
            dpr_audit_log($mysqli, $user['id'], 'DELETE', 'units', $uid, 'Desactivó unidad');
            $msg = 'Unidad desactivada.'; $type = 'success';
        }
        $action = 'list';
    }
}

// GET para editar
$unit = [];
if ($action === 'edit' && $unit_id) {
    $rows = db_query($mysqli, "SELECT * FROM units WHERE id=?", 'i', [$unit_id]);
    $unit = $rows[0] ?? [];
    if (!$unit) $action = 'list';
}

// Listado con filtro por inmueble
$where  = $filter_prop ? "WHERE u.property_id = $filter_prop AND u.estado != 'inactiva'" : "WHERE u.estado != 'inactiva'";
$units  = db_query($mysqli,
    "SELECT u.*, p.nombre AS property_name,
            CONCAT(us.nombre,' ',us.apellido) AS inquilino_nombre,
            l.id AS lease_id
     FROM units u
     JOIN properties p ON p.id = u.property_id
     LEFT JOIN leases l  ON l.unit_id = u.id AND l.estado = 'activo'
     LEFT JOIN users us  ON us.id = l.tenant_id
     $where
     ORDER BY p.nombre, u.nombre");

$all_props = db_query($mysqli, "SELECT id, nombre FROM properties WHERE estado='activo' ORDER BY nombre");

$page_title  = 'Unidades';
$active_menu = 'units';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<?php if ($action === 'new' || $action === 'edit'): ?>
<!-- ========================= FORM ========================= -->
<div class="dpr-card">
  <div class="dpr-card__title"><?= $action === 'edit' ? 'Editar unidad' : 'Nueva unidad' ?></div>
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id"     value="<?= (int)($unit['id'] ?? 0) ?>">
    <div class="dpr-form-grid">
      <div class="dpr-form-group">
        <label class="dpr-label">Inmueble *</label>
        <select name="property_id" class="dpr-select" required>
          <option value="">Seleccionar...</option>
          <?php foreach ($all_props as $p): ?>
          <option value="<?= $p['id'] ?>" <?= ($unit['property_id'] ?? $filter_prop) == $p['id'] ? 'selected' : '' ?>><?= h($p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Nombre de la unidad *</label>
        <input type="text" name="nombre" class="dpr-input" required value="<?= h($unit['nombre'] ?? '') ?>" placeholder="Ej: Apto 301, Local 02, Hab. A">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Tipo *</label>
        <select name="tipo" class="dpr-select">
          <?php foreach (['apartamento','local','habitacion','oficina','bodega','otro'] as $t): ?>
          <option value="<?= $t ?>" <?= ($unit['tipo'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Piso</label>
        <input type="number" name="piso" class="dpr-input" min="0" max="99" value="<?= h($unit['piso'] ?? '') ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Área (m²)</label>
        <input type="number" name="area_m2" class="dpr-input" step="0.01" min="0" value="<?= h($unit['area_m2'] ?? '') ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Valor arriendo (COP) *</label>
        <input type="number" name="valor_arriendo" class="dpr-input" required min="0" step="1000" value="<?= h($unit['valor_arriendo'] ?? '') ?>" placeholder="1450000">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Estado</label>
        <select name="estado" class="dpr-select" id="estadoSelect" onchange="toggleOcupanteTipo()">
          <?php foreach (['disponible','ocupada','mantenimiento'] as $e): ?>
          <option value="<?= $e ?>" <?= ($unit['estado'] ?? '') === $e ? 'selected' : '' ?>><?= ucfirst($e) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group" id="ocupanteTipoGroup">
        <label class="dpr-label">Ocupante</label>
        <select name="ocupante_tipo" class="dpr-select">
          <option value="inquilino" <?= ($unit['ocupante_tipo'] ?? 'inquilino') === 'inquilino' ? 'selected' : '' ?>>Inquilino (con contrato de arriendo)</option>
          <option value="propietario" <?= ($unit['ocupante_tipo'] ?? '') === 'propietario' ? 'selected' : '' ?>>Propietario / administrador (no paga arriendo)</option>
        </select>
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
          "Propietario" permite incluir la unidad en el reparto de servicios públicos sin necesitar un contrato de arriendo.
        </div>
      </div>
      <div class="dpr-form-group dpr-form-group--full">
        <label class="dpr-label">Descripción / Notas</label>
        <textarea name="descripcion" class="dpr-textarea"><?= h($unit['descripcion'] ?? '') ?></textarea>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Imagen principal</label>
        <?php if (!empty($unit['imagen_url'])): ?>
        <div style="margin-bottom:6px"><img src="<?= h($unit['imagen_url']) ?>" style="max-height:80px;border-radius:6px;border:.5px solid var(--border)"></div>
        <?php endif; ?>
        <input type="file" name="imagen" class="dpr-input" accept=".jpg,.jpeg,.png,.webp">
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">Se optimiza a WebP máx 800px automáticamente</div>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Video embed (iframe YouTube/Vimeo)</label>
        <textarea name="video_embed" class="dpr-textarea" style="height:64px" placeholder='&lt;iframe src="https://www.youtube.com/embed/ID" ...&gt;&lt;/iframe&gt;'><?= h($unit['video_embed'] ?? '') ?></textarea>
      </div>
      <div class="dpr-form-group dpr-form-group--full" style="flex-direction:row;align-items:center;gap:10px;padding:12px;background:var(--gray-card);border-radius:var(--radius-sm);border:.5px solid var(--border)">
        <input type="checkbox" name="publicar" id="chk_pub" value="1" <?= ($unit['publicar'] ?? 0) ? 'checked' : '' ?>>
        <label for="chk_pub" style="cursor:pointer;font-size:13px;color:var(--text)">
          <strong>Publicar en página pública de unidades disponibles</strong><br>
          <span style="font-size:12px;color:var(--text-muted)">Solo se mostrará si la unidad tiene estado "disponible"</span>
        </label>
      </div>
    </div>
    <div class="dpr-form-actions">
      <a href="units.php" class="dpr-btn dpr-btn--secondary">Cancelar</a>
      <button type="submit" class="dpr-btn dpr-btn--primary">Guardar unidad</button>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ========================= LIST ========================= -->
<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Unidades</div>
    <div class="dpr-page-sub"><?= count($units) ?> unidades · Filtrado por: <?= $filter_prop ? h(($all_props[array_search($filter_prop, array_column($all_props,'id'))]['nombre'] ?? 'Todos')) : 'Todos los inmuebles' ?></div>
  </div>
  <div class="dpr-flex">
    <form method="GET" style="display:flex;gap:8px;align-items:center">
      <select name="property_id" class="dpr-select" style="width:200px" onchange="this.form.submit()">
        <option value="">Todos los inmuebles</option>
        <?php foreach ($all_props as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $filter_prop == $p['id'] ? 'selected' : '' ?>><?= h($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="?action=new<?= $filter_prop ? "&property_id=$filter_prop" : '' ?>" class="dpr-btn dpr-btn--primary">+ Nueva unidad</a>
  </div>
</div>

<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr><th>Unidad</th><th>Inmueble</th><th>Tipo</th><th>Piso</th><th>Área</th><th>Arriendo</th><th>Inquilino</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (!$units): ?>
        <tr><td colspan="9" class="dpr-text-muted" style="text-align:center;padding:24px">No hay unidades registradas.</td></tr>
        <?php endif; ?>
        <?php foreach ($units as $u):
          $pill = match($u['estado']) {
            'ocupada'       => 'ok',
            'disponible'    => 'info',
            'mantenimiento' => 'warn',
            default         => 'neutral'
          };
        ?>
        <tr>
          <td><strong><?= h($u['nombre']) ?></strong></td>
          <td><?= h($u['property_name']) ?></td>
          <td><?= ucfirst($u['tipo']) ?></td>
          <td><?= $u['piso'] ?? '—' ?></td>
          <td><?= $u['area_m2'] ? number_format($u['area_m2'],1).' m²' : '—' ?></td>
          <td><?= fmt_money($u['valor_arriendo']) ?></td>
          <td>
            <?php if ($u['inquilino_nombre']): ?>
              <a href="leases.php?unit_id=<?= $u['id'] ?>" style="color:var(--blue)"><?= h($u['inquilino_nombre']) ?></a>
            <?php elseif (($u['ocupante_tipo'] ?? '') === 'propietario'): ?>
              <span class="dpr-pill dpr-pill--info" style="font-size:11px">Propietario</span>
            <?php else: ?>
              <span class="dpr-text-muted">Disponible</span>
            <?php endif; ?>
          </td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst($u['estado']) ?></span></td>
          <td class="dpr-flex">
            <a href="?action=edit&id=<?= $u['id'] ?>" class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Editar">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 2.5l2 2L4 12H2v-2L9.5 2.5Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
            </a>
            <?php if (!$u['lease_id']): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('¿Desactivar esta unidad?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id"     value="<?= $u['id'] ?>">
              <button class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Desactivar">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 4h10M5 4V2h4v2M6 7v4M8 7v4M3 4l1 8h6l1-8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
            </form>
            <?php endif; ?>
            <a href="meters.php?unit_id=<?= $u['id'] ?>" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Medidores</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
function toggleOcupanteTipo() {
  const estado = document.getElementById('estadoSelect').value;
  const grp    = document.getElementById('ocupanteTipoGroup');
  if (grp) grp.style.display = (estado === 'ocupada') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleOcupanteTipo);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
