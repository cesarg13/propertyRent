<?php
// =============================================================
//  PropertyRent — Admin: Log de auditoría
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$f_tabla  = $_GET['tabla']  ?? '';
$f_accion = $_GET['accion'] ?? '';
$f_user   = $_GET['user_id'] ?? '';
$page_num = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page_num - 1) * $per_page;

$where_parts = ['1=1'];
if ($f_tabla)  $where_parts[] = "al.tabla = '"  . $mysqli->real_escape_string($f_tabla)  . "'";
if ($f_accion) $where_parts[] = "al.accion = '" . $mysqli->real_escape_string($f_accion) . "'";
if ($f_user)   $where_parts[] = "al.user_id = " . (int)$f_user;
$where = implode(' AND ', $where_parts);

$total_rows = db_query($mysqli, "SELECT COUNT(*) AS n FROM audit_log al WHERE $where");
$total      = (int)($total_rows[0]['n'] ?? 0);
$pages      = $total > 0 ? (int)ceil($total / $per_page) : 1;

// LEFT JOIN para no perder registros si el usuario fue eliminado
$logs = db_query($mysqli,
    "SELECT al.*,
            COALESCE(CONCAT(u.nombre,' ',u.apellido), CONCAT('Usuario #',al.user_id)) AS actor
     FROM audit_log al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE $where
     ORDER BY al.created_at DESC
     LIMIT $per_page OFFSET $offset");

// Acciones disponibles para el filtro (desde la BD)
$acciones_bd = db_query($mysqli,
    "SELECT DISTINCT accion FROM audit_log ORDER BY accion");
$acciones = array_column($acciones_bd, 'accion');

// Tablas disponibles
$tablas_bd = db_query($mysqli,
    "SELECT DISTINCT tabla FROM audit_log ORDER BY tabla");
$tablas = array_column($tablas_bd, 'tabla');

// Usuarios activos para filtro
$usuarios = db_query($mysqli,
    "SELECT id, CONCAT(nombre,' ',apellido) AS nombre FROM users ORDER BY nombre");

$page_title  = 'Auditoría';
$active_menu = 'audit';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Log de auditoría</div>
    <div class="dpr-page-sub">
      Registro de todas las acciones del sistema
      · <strong><?= number_format($total) ?></strong> eventos
    </div>
  </div>
</div>

<form method="GET" class="dpr-card" style="padding:12px 18px;margin-bottom:14px">
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
    <div class="dpr-form-group" style="margin-bottom:0">
      <label class="dpr-label">Tabla</label>
      <select name="tabla" class="dpr-select" style="width:160px">
        <option value="">Todas</option>
        <?php foreach ($tablas as $t): ?>
        <option value="<?= h($t) ?>" <?= $f_tabla===$t ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group" style="margin-bottom:0">
      <label class="dpr-label">Acción</label>
      <select name="accion" class="dpr-select" style="width:180px">
        <option value="">Todas</option>
        <?php foreach ($acciones as $a): ?>
        <option value="<?= h($a) ?>" <?= $f_accion===$a ? 'selected' : '' ?>><?= h($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group" style="margin-bottom:0">
      <label class="dpr-label">Usuario</label>
      <select name="user_id" class="dpr-select" style="width:180px">
        <option value="">Todos</option>
        <?php foreach ($usuarios as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $f_user == $u['id'] ? 'selected' : '' ?>><?= h($u['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:6px">
      <button class="dpr-btn dpr-btn--primary">Filtrar</button>
      <a href="?" class="dpr-btn dpr-btn--secondary">Limpiar</a>
    </div>
  </div>
</form>

<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr>
          <th>Fecha / Hora</th><th>Actor</th><th>Acción</th>
          <th>Tabla</th><th>ID</th><th>Detalle</th><th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?>
        <tr><td colspan="7" class="dpr-text-muted" style="text-align:center;padding:24px">
          Sin registros para los filtros aplicados.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $log):
            $accion = $log['accion'] ?? '';
            // Pill color sin match() — compatible con cualquier PHP 7+
            $ok_actions      = ['INSERT','VALIDATE','REGISTER_PAYMENT','ABONO','SALDO_FAVOR','UPLOAD'];
            $warn_actions    = ['UPDATE','ABONO_SERVICIO'];
            $danger_actions  = ['DELETE','REJECT'];
            if (in_array($accion, $ok_actions))     $pill = 'ok';
            elseif (in_array($accion, $warn_actions)) $pill = 'warn';
            elseif (in_array($accion, $danger_actions)) $pill = 'danger';
            else $pill = 'neutral';
        ?>
        <tr>
          <td style="white-space:nowrap;font-size:12px">
            <?= h(date('d/m/Y H:i:s', strtotime($log['created_at']))) ?>
          </td>
          <td style="font-size:13px"><?= h($log['actor']) ?></td>
          <td>
            <span class="dpr-pill dpr-pill--<?= $pill ?>">
              <?= h($accion) ?>
            </span>
          </td>
          <td style="font-family:monospace;font-size:12px;color:var(--text-muted)">
            <?= h($log['tabla']) ?>
          </td>
          <td style="text-align:center;font-size:12px">
            <?= $log['registro_id'] ? (int)$log['registro_id'] : '—' ?>
          </td>
          <td style="font-size:12px;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
              title="<?= h($log['detalle'] ?? '') ?>">
            <?= h($log['detalle'] ?? '') ?>
          </td>
          <td style="font-size:11px;font-family:monospace;color:var(--text-muted)">
            <?= h($log['ip'] ?? '') ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:4px;margin-top:14px;justify-content:flex-end;flex-wrap:wrap">
    <?php if ($page_num > 1): ?>
    <a href="?tabla=<?= h($f_tabla) ?>&accion=<?= h($f_accion) ?>&user_id=<?= h($f_user) ?>&page=<?= $page_num-1 ?>"
       class="dpr-btn dpr-btn--secondary dpr-btn--sm">‹ Ant.</a>
    <?php endif; ?>
    <?php
    // Mostrar máximo 7 páginas alrededor de la actual
    $start = max(1, $page_num - 3);
    $end   = min($pages, $page_num + 3);
    for ($i = $start; $i <= $end; $i++):
    ?>
    <a href="?tabla=<?= h($f_tabla) ?>&accion=<?= h($f_accion) ?>&user_id=<?= h($f_user) ?>&page=<?= $i ?>"
       style="padding:5px 10px;border-radius:5px;font-size:12px;text-decoration:none;
              background:<?= $i===$page_num ? 'var(--blue)' : 'var(--gray-card)' ?>;
              color:<?= $i===$page_num ? '#fff' : 'var(--text-muted)' ?>;
              border:0.5px solid var(--border)">
      <?= $i ?>
    </a>
    <?php endfor; ?>
    <?php if ($page_num < $pages): ?>
    <a href="?tabla=<?= h($f_tabla) ?>&accion=<?= h($f_accion) ?>&user_id=<?= h($f_user) ?>&page=<?= $page_num+1 ?>"
       class="dpr-btn dpr-btn--secondary dpr-btn--sm">Sig. ›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
