<?php
// =============================================================
//  PropertyRent — Admin: Gestión de Inquilinos
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$user    = dpr_current_user();
$action  = $_GET['action'] ?? 'list';
$ten_id  = (int)($_GET['id'] ?? 0);
$msg = $type = '';

// ---------------------------------------------------------------
// POST
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $tid      = (int)($_POST['id']       ?? 0);
        $nombre   = trim($_POST['nombre']    ?? '');
        $apellido = trim($_POST['apellido']  ?? '');
        $email    = trim($_POST['email']     ?? '');
        $tel      = trim($_POST['telefono']  ?? '');
        $cedula   = trim($_POST['cedula']    ?? '');
        $password = trim($_POST['password']  ?? '');

        if (!$nombre || !$apellido || !$email) {
            $msg = 'Nombre, apellido y email son obligatorios.'; $type = 'danger';
        } else {
            // Verificar email duplicado (excluir el propio registro en edición)
            $dup = db_query($mysqli,
                "SELECT id FROM users WHERE email=? AND id != ?",
                'si', [$email, $tid ?: 0]);
            if ($dup) {
                $msg = 'Ya existe un usuario registrado con ese email. Por favor usa uno diferente.'; $type = 'danger';
            } elseif ($tid) {
                // Actualizar — si viene contraseña nueva, hashearla
                try {
                    if ($password) {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        db_execute($mysqli,
                            "UPDATE users SET nombre=?,apellido=?,email=?,telefono=?,cedula=?,password_hash=?,updated_at=NOW() WHERE id=?",
                            'ssssssi', [$nombre,$apellido,$email,$tel,$cedula,$hash,$tid]);
                    } else {
                        db_execute($mysqli,
                            "UPDATE users SET nombre=?,apellido=?,email=?,telefono=?,cedula=?,updated_at=NOW() WHERE id=?",
                            'sssssi', [$nombre,$apellido,$email,$tel,$cedula,$tid]);
                    }
                    dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'users', $tid, "Editó inquilino: $nombre $apellido");
                    $msg = 'Inquilino actualizado.'; $type = 'success';
                } catch (mysqli_sql_exception $e) {
                    error_log("tenants UPDATE error: " . $e->getMessage());
                    $msg = 'Error al actualizar el inquilino. Verifica los datos e intenta de nuevo.'; $type = 'danger';
                }
            } else {
                if (!$password) {
                    $msg = 'La contraseña es obligatoria para nuevos inquilinos.'; $type = 'danger';
                } else {
                    try {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $r = db_execute($mysqli,
                            "INSERT INTO users (nombre,apellido,email,password_hash,telefono,cedula,rol) VALUES (?,?,?,?,?,?,'tenant')",
                            'ssssss', [$nombre,$apellido,$email,$hash,$tel,$cedula]);
                        dpr_audit_log($mysqli, $user['id'], 'INSERT', 'users', $r['insert_id'], "Creó inquilino: $nombre $apellido");
                        $msg = 'Inquilino creado. Ya puede iniciar sesión.'; $type = 'success';
                    } catch (mysqli_sql_exception $e) {
                        error_log("tenants INSERT error: " . $e->getMessage());
                        $msg = 'No se pudo crear el inquilino. Es posible que el email ya esté registrado.'; $type = 'danger';
                    }
                }
            }
        }
        $action = 'list';
    }

    if ($act === 'toggle') {
        $tid     = (int)($_POST['id'] ?? 0);
        $estado  = $_POST['estado_actual'] === 'activo' ? 'inactivo' : 'activo';
        db_execute($mysqli, "UPDATE users SET estado=? WHERE id=?", 'si', [$estado, $tid]);
        dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'users', $tid, "Cambió estado a: $estado");
        $msg = "Estado cambiado a $estado."; $type = 'success';
        $action = 'list';
    }
}

// GET editar
$tenant = [];
if ($action === 'edit' && $ten_id) {
    $rows = db_query($mysqli, "SELECT * FROM users WHERE id=? AND rol='tenant'", 'i', [$ten_id]);
    $tenant = $rows[0] ?? [];
    if (!$tenant) $action = 'list';
}

// Listado con estado de pagos
$tenants = db_query($mysqli,
    "SELECT u.*,
            un.nombre AS unidad, pr.nombre AS inmueble,
            l.id AS lease_id,
            (SELECT estado FROM payments
             WHERE lease_id = l.id AND periodo = DATE_FORMAT(CURDATE(),'%Y-%m')
             LIMIT 1) AS estado_pago
     FROM users u
     LEFT JOIN leases l      ON l.tenant_id = u.id AND l.estado = 'activo'
     LEFT JOIN units un      ON un.id = l.unit_id
     LEFT JOIN properties pr ON pr.id = un.property_id
     WHERE u.rol = 'tenant'
     ORDER BY u.apellido, u.nombre");

$page_title  = 'Inquilinos';
$active_menu = 'tenants';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<?php if ($action === 'new' || $action === 'edit'): ?>
<div class="dpr-card">
  <div class="dpr-card__title"><?= $action === 'edit' ? 'Editar inquilino' : 'Nuevo inquilino' ?></div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id"     value="<?= (int)($tenant['id'] ?? 0) ?>">
    <div class="dpr-form-grid">
      <div class="dpr-form-group">
        <label class="dpr-label">Nombre *</label>
        <input type="text" name="nombre" class="dpr-input" required value="<?= h($tenant['nombre'] ?? '') ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Apellido *</label>
        <input type="text" name="apellido" class="dpr-input" required value="<?= h($tenant['apellido'] ?? '') ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Email * (usado para login)</label>
        <input type="email" name="email" class="dpr-input" required value="<?= h($tenant['email'] ?? '') ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Teléfono</label>
        <input type="text" name="telefono" class="dpr-input" value="<?= h($tenant['telefono'] ?? '') ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Cédula / Documento</label>
        <input type="text" name="cedula" class="dpr-input" value="<?= h($tenant['cedula'] ?? '') ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label"><?= $action === 'edit' ? 'Nueva contraseña (dejar vacío = no cambiar)' : 'Contraseña *' ?></label>
        <input type="password" name="password" class="dpr-input" <?= $action === 'new' ? 'required' : '' ?> autocomplete="new-password">
      </div>
    </div>
    <div class="dpr-form-actions">
      <a href="tenants.php" class="dpr-btn dpr-btn--secondary">Cancelar</a>
      <button type="submit" class="dpr-btn dpr-btn--primary">Guardar inquilino</button>
    </div>
  </form>
</div>

<?php else: ?>
<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Inquilinos</div>
    <div class="dpr-page-sub"><?= count($tenants) ?> inquilinos registrados</div>
  </div>
  <a href="?action=new" class="dpr-btn dpr-btn--primary">+ Nuevo inquilino</a>
</div>

<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr><th>Nombre</th><th>Email</th><th>Cédula</th><th>Unidad</th><th>Pago actual</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tenants as $t):
          $pago_pill = match($t['estado_pago'] ?? '') {
            'pagado'    => ['ok',      'Pagado'],
            'validando' => ['warn',    'Validando'],
            'moroso'    => ['danger',  'En mora'],
            'pendiente' => ['info',    'Pendiente'],
            default     => ['neutral', '—'],
          };
          $est_pill = $t['estado'] === 'activo' ? 'ok' : 'danger';
        ?>
        <tr>
          <td><strong><?= h($t['nombre'].' '.$t['apellido']) ?></strong></td>
          <td><?= h($t['email']) ?></td>
          <td><?= h($t['cedula'] ?? '—') ?></td>
          <td><?= $t['unidad'] ? h($t['inmueble'].' / '.$t['unidad']) : '<span class="dpr-text-muted">Sin contrato</span>' ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $pago_pill[0] ?>"><?= $pago_pill[1] ?></span></td>
          <td><span class="dpr-pill dpr-pill--<?= $est_pill ?>"><?= ucfirst($t['estado']) ?></span></td>
          <td class="dpr-flex">
            <a href="?action=edit&id=<?= $t['id'] ?>" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Editar</a>
            <a href="leases.php?tenant_id=<?= $t['id'] ?>" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Contrato</a>
            <form method="POST" style="display:inline" onsubmit="return confirm('¿Cambiar estado del inquilino?')">
              <input type="hidden" name="action"        value="toggle">
              <input type="hidden" name="id"            value="<?= $t['id'] ?>">
              <input type="hidden" name="estado_actual" value="<?= $t['estado'] ?>">
              <button class="dpr-btn dpr-btn--sm dpr-btn--<?= $t['estado']==='activo'?'danger':'icon' ?>">
                <?= $t['estado'] === 'activo' ? 'Suspender' : 'Activar' ?>
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
