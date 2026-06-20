<?php
// =============================================================
//  PropertyRent — Admin: Configuración de Mora
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$user = dpr_current_user();
$msg = $type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'save_mora') {
    $property_id  = $_POST['property_id'] !== '' ? (int)$_POST['property_id'] : null;
    $tasa         = (float)$_POST['tasa_mora_mensual'];
    $gracia       = (int)$_POST['dias_gracia'];
    $notif        = (int)$_POST['notificacion_dias_antes'];
    $compuesto    = isset($_POST['aplica_interes_compuesto']) ? 1 : 0;

    // Upsert: si ya existe config para ese property_id, actualizar; si no, insertar
    $exists = db_query($mysqli,
        "SELECT id FROM mora_config WHERE " . ($property_id ? "property_id=$property_id" : "property_id IS NULL") . " AND activo=1");
    if ($exists) {
        db_execute($mysqli,
            "UPDATE mora_config SET tasa_mora_mensual=?,dias_gracia=?,notificacion_dias_antes=?,aplica_interes_compuesto=?,updated_at=NOW()
             WHERE id=?",
            'diiis', [$tasa,$gracia,$notif,$compuesto,$exists[0]['id']]);
    } else {
        db_execute($mysqli,
            "INSERT INTO mora_config (property_id,tasa_mora_mensual,dias_gracia,notificacion_dias_antes,aplica_interes_compuesto)
             VALUES (?,?,?,?,?)",
            'idiis', [$property_id,$tasa,$gracia,$notif,$compuesto]);
    }
    dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'mora_config', null, "Config mora tasa=$tasa gracia=$gracia");
    $msg = 'Configuración de mora guardada.'; $type = 'success';
}

$configs   = db_query($mysqli,
    "SELECT mc.*, p.nombre AS inmueble FROM mora_config mc
     LEFT JOIN properties p ON p.id = mc.property_id
     WHERE mc.activo = 1 ORDER BY mc.property_id ASC");
$all_props = db_query($mysqli, "SELECT id, nombre FROM properties WHERE estado='activo' ORDER BY nombre");

$page_title  = 'Config. Mora';
$active_menu = 'mora';
require_once __DIR__ . '/../includes/header.php';
if ($msg): ?><div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Configuración de mora</div>
    <div class="dpr-page-sub">Reglas globales y por inmueble. La config específica tiene prioridad sobre la global.</div>
  </div>
</div>

<div class="dpr-grid-2">
  <!-- Formulario -->
  <div class="dpr-card">
    <div class="dpr-card__title">Nueva / editar configuración</div>
    <form method="POST">
      <input type="hidden" name="action" value="save_mora">
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Aplica a (vacío = global)</label>
        <select name="property_id" class="dpr-select">
          <option value="">Global — todos los inmuebles</option>
          <?php foreach ($all_props as $p): ?>
          <option value="<?= $p['id'] ?>"><?= h($p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Tasa de mora mensual (%)</label>
        <input type="number" name="tasa_mora_mensual" class="dpr-input" step="0.01" min="0" max="100" value="1.50" required>
        <span style="font-size:11px;color:var(--text-muted)">Ej: 1.50 = 1.5% mensual sobre el valor del arriendo</span>
      </div>
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Días de gracia antes de aplicar mora</label>
        <input type="number" name="dias_gracia" class="dpr-input" min="0" max="30" value="5" required>
      </div>
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Notificar al inquilino (días antes del vencimiento)</label>
        <input type="number" name="notificacion_dias_antes" class="dpr-input" min="1" max="30" value="3" required>
      </div>
      <div class="dpr-form-group" style="margin-bottom:16px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
          <input type="checkbox" name="aplica_interes_compuesto">
          Aplicar interés compuesto (meses acumulados)
        </label>
      </div>
      <button type="submit" class="dpr-btn dpr-btn--primary">Guardar configuración</button>
    </form>
  </div>

  <!-- Tabla de configs actuales -->
  <div class="dpr-card">
    <div class="dpr-card__title">Configuraciones activas</div>
    <table class="dpr-table">
      <thead><tr><th>Aplica a</th><th>Tasa</th><th>Gracia</th><th>Notif.</th></tr></thead>
      <tbody>
        <?php foreach ($configs as $c): ?>
        <tr>
          <td><?= $c['inmueble'] ? h($c['inmueble']) : '<strong>Global</strong>' ?></td>
          <td><?= number_format($c['tasa_mora_mensual'],2) ?>%</td>
          <td><?= $c['dias_gracia'] ?> días</td>
          <td><?= $c['notificacion_dias_antes'] ?> días antes</td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$configs): ?>
        <tr><td colspan="4" class="dpr-text-muted" style="text-align:center">Sin configuración. Crea una global primero.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
