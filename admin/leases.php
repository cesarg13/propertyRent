<?php
// =============================================================
//  PropertyRent — Admin: Contratos de Arrendamiento
//  Lógica: pago adelantado, vence (día_inicio - 5) del período
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$user      = dpr_current_user();
$action    = $_GET['action']    ?? 'list';
$lease_id  = (int)($_GET['id'] ?? 0);
$filter_t  = (int)($_GET['tenant_id'] ?? 0);
$filter_u  = (int)($_GET['unit_id']   ?? 0);
$msg = $type = '';

// ---------------------------------------------------------------
// POST: guardar / terminar contrato
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $lid          = (int)($_POST['id']           ?? 0);
        $unit_id      = (int)($_POST['unit_id']      ?? 0);
        $tenant_id    = (int)($_POST['tenant_id']    ?? 0);
        $fecha_inicio = $_POST['fecha_inicio']        ?? '';
        $fecha_fin    = $_POST['fecha_fin']            !== '' ? $_POST['fecha_fin'] : null;
        $valor        = (float)($_POST['valor_mensual'] ?? 0);
        $deposito     = $_POST['deposito'] !== '' ? (float)$_POST['deposito'] : null;
        $notas        = trim($_POST['notas']           ?? '');

        // día_vencimiento = día del mes de fecha_inicio
        $dia_inicio   = (int)date('d', strtotime($fecha_inicio));

        if (!$unit_id || !$tenant_id || !$fecha_inicio || $valor <= 0) {
            $msg = 'Unidad, inquilino, fecha inicio y valor son obligatorios.'; $type = 'danger';
        } else {
            if ($lid) {
                db_execute($mysqli,
                    "UPDATE leases SET unit_id=?,tenant_id=?,fecha_inicio=?,fecha_fin=?,valor_mensual=?,dia_vencimiento=?,deposito=?,notas=?,updated_at=NOW() WHERE id=?",
                    'iissdiisi', [$unit_id,$tenant_id,$fecha_inicio,$fecha_fin,$valor,$dia_inicio,$deposito,$notas,$lid]);
                dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'leases', $lid, "Editó contrato lease_id=$lid");
                $msg = 'Contrato actualizado.'; $type = 'success';
            } else {
                // Verificar que la unidad no tenga contrato activo
                $existe = db_query($mysqli, "SELECT id FROM leases WHERE unit_id=? AND estado='activo' LIMIT 1", 'i', [$unit_id]);
                if ($existe) {
                    $msg = 'La unidad ya tiene un contrato activo. Termínalo antes de crear uno nuevo.'; $type = 'danger';
                } else {
                    $r = db_execute($mysqli,
                        "INSERT INTO leases (unit_id,tenant_id,fecha_inicio,fecha_fin,valor_mensual,dia_vencimiento,deposito,notas) VALUES (?,?,?,?,?,?,?,?)",
                        'iissdiis', [$unit_id,$tenant_id,$fecha_inicio,$fecha_fin,$valor,$dia_inicio,$deposito,$notas]);
                    $new_lid = $r['insert_id'];
                    // Marcar unidad como ocupada
                    db_execute($mysqli, "UPDATE units SET estado='ocupada' WHERE id=?", 'i', [$unit_id]);
                    // Generar períodos de pago desde inicio hasta hoy
                    $periodos = dpr_generar_periodos($fecha_inicio);
                    foreach ($periodos as $per) {
                        $venc = $per['fecha_vencimiento'];
                        db_execute($mysqli,
                            "INSERT IGNORE INTO payments (lease_id,periodo,valor_arriendo,valor_mora,valor_total,fecha_vencimiento,estado)
                             VALUES (?,?,?,0,?,?,'pendiente')",
                            'isdds', [$new_lid, $per['periodo'], $valor, $valor, $venc]);
                    }
                    dpr_audit_log($mysqli, $user['id'], 'INSERT', 'leases', $new_lid, "Creó contrato unidad_id=$unit_id tenant_id=$tenant_id");
                    $msg = 'Contrato creado y períodos de pago generados.'; $type = 'success';
                }
            }
        }
        $action = 'list';
    }

    if ($act === 'terminate') {
        $lid = (int)($_POST['id'] ?? 0);
        db_execute($mysqli, "UPDATE leases SET estado='terminado',fecha_fin=CURDATE(),updated_at=NOW() WHERE id=?", 'i', [$lid]);
        // Marcar unidad disponible
        $rows = db_query($mysqli, "SELECT unit_id FROM leases WHERE id=?", 'i', [$lid]);
        if ($rows) db_execute($mysqli, "UPDATE units SET estado='disponible' WHERE id=?", 'i', [$rows[0]['unit_id']]);
        dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'leases', $lid, 'Terminó contrato');
        $msg = 'Contrato terminado.'; $type = 'success';
        $action = 'list';
    }
}

// GET editar
$lease = [];
if ($action === 'edit' && $lease_id) {
    $rows = db_query($mysqli, "SELECT * FROM leases WHERE id=?", 'i', [$lease_id]);
    $lease = $rows[0] ?? [];
}

// Listado
$where_parts = ["l.estado IN ('activo','terminado')"];
if ($filter_t) $where_parts[] = "l.tenant_id = $filter_t";
if ($filter_u) $where_parts[] = "l.unit_id = $filter_u";
$where = implode(' AND ', $where_parts);

$leases = db_query($mysqli,
    "SELECT l.*, CONCAT(u.nombre,' ',u.apellido) AS inquilino,
            un.nombre AS unidad, pr.nombre AS inmueble,
			(CAST(l.dia_vencimiento AS SIGNED) - 5) AS dia_pago_hasta
     FROM leases l
     JOIN users u  ON u.id = l.tenant_id
     JOIN units un ON un.id = l.unit_id
     JOIN properties pr ON pr.id = un.property_id
     WHERE $where
     ORDER BY l.estado DESC, pr.nombre, un.nombre");

$all_units   = db_query($mysqli,
    "SELECT u.id, CONCAT(p.nombre,' / ',u.nombre) AS label, u.valor_arriendo
     FROM units u JOIN properties p ON p.id=u.property_id
     WHERE u.estado != 'inactiva' ORDER BY p.nombre, u.nombre");
$all_tenants = db_query($mysqli,
    "SELECT id, CONCAT(nombre,' ',apellido,' — ',email) AS label FROM users WHERE rol='tenant' AND estado='activo' ORDER BY apellido");

$page_title  = 'Contratos';
$active_menu = 'leases';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<?php if ($action === 'new' || $action === 'edit'): ?>
<div class="dpr-card">
  <div class="dpr-card__title"><?= $action === 'edit' ? 'Editar contrato' : 'Nuevo contrato' ?></div>
  <p class="dpr-text-muted" style="margin-bottom:14px;font-size:12px">
    El día de vencimiento del pago adelantado se calcula automáticamente como: <strong>día de inicio del contrato − 5 días</strong>.<br>
    Ejemplo: contrato inicia el 15 → el pago de cada período vence el día 10 del mismo mes.
  </p>
  <form method="POST" id="leaseForm">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id"     value="<?= (int)($lease['id'] ?? 0) ?>">
    <div class="dpr-form-grid">
      <div class="dpr-form-group">
        <label class="dpr-label">Unidad *</label>
        <select name="unit_id" class="dpr-select" required id="unitSelect">
          <option value="">Seleccionar unidad...</option>
          <?php foreach ($all_units as $u): ?>
          <option value="<?= $u['id'] ?>" data-valor="<?= $u['valor_arriendo'] ?>"
            <?= ($lease['unit_id'] ?? $filter_u) == $u['id'] ? 'selected' : '' ?>>
            <?= h($u['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Inquilino *</label>
        <select name="tenant_id" class="dpr-select" required>
          <option value="">Seleccionar inquilino...</option>
          <?php foreach ($all_tenants as $t): ?>
          <option value="<?= $t['id'] ?>" <?= ($lease['tenant_id'] ?? $filter_t) == $t['id'] ? 'selected' : '' ?>><?= h($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Fecha de inicio del contrato *</label>
        <input type="date" name="fecha_inicio" id="fechaInicio" class="dpr-input" required value="<?= h($lease['fecha_inicio'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Fecha de terminación (vacío = indefinido)</label>
        <input type="date" name="fecha_fin" class="dpr-input" value="<?= h($lease['fecha_fin'] ?? '') ?>">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Valor mensual (COP) *</label>
        <input type="number" name="valor_mensual" id="valorMensual" class="dpr-input" required min="0" step="1000"
          value="<?= h($lease['valor_mensual'] ?? '') ?>" placeholder="Se autocompletará al elegir unidad">
      </div>
      <div class="dpr-form-group">
        <label class="dpr-label">Depósito de garantía</label>
        <input type="number" name="deposito" class="dpr-input" min="0" step="1000" value="<?= h($lease['deposito'] ?? '') ?>">
      </div>
      <div class="dpr-form-group dpr-form-group--full">
        <label class="dpr-label">Día de vencimiento (calculado automáticamente)</label>
        <div class="dpr-input" id="diaVencDisplay" style="background:#f8fafc;color:#64748b">
          Selecciona una fecha de inicio para ver el día de vencimiento
        </div>
      </div>
      <div class="dpr-form-group dpr-form-group--full">
        <label class="dpr-label">Notas / Condiciones especiales</label>
        <textarea name="notas" class="dpr-textarea"><?= h($lease['notas'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="dpr-form-actions">
      <a href="leases.php" class="dpr-btn dpr-btn--secondary">Cancelar</a>
      <button type="submit" class="dpr-btn dpr-btn--primary">Guardar contrato</button>
    </div>
  </form>
</div>
<script>
document.getElementById('unitSelect').addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  const val = opt.dataset.valor;
  if (val) document.getElementById('valorMensual').value = val;
});
document.getElementById('fechaInicio').addEventListener('change', function() {
  const d = new Date(this.value + 'T12:00:00');
  if (!d) return;
  const dia = d.getDate();
  const diaVenc = Math.max(1, dia - 5);
  document.getElementById('diaVencDisplay').textContent =
    `El pago de cada periodo vence el día ${diaVenc} del mes correspondiente (${dia} − 5 días de gracia de cobro adelantado)`;
});
document.getElementById('fechaInicio').dispatchEvent(new Event('change'));
</script>

<?php else: ?>
<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Contratos de arrendamiento</div>
    <div class="dpr-page-sub"><?= count($leases) ?> contratos</div>
  </div>
  <a href="?action=new" class="dpr-btn dpr-btn--primary">+ Nuevo contrato</a>
</div>

<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr><th>Inquilino</th><th>Inmueble / Unidad</th><th>Inicio</th><th>Fin</th><th>Valor</th><th>Vence día</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($leases as $l):
          $pill = $l['estado'] === 'activo' ? 'ok' : 'neutral';
        ?>
        <tr>
          <td><?= h($l['inquilino']) ?></td>
          <td><?= h($l['inmueble'].' / '.$l['unidad']) ?></td>
          <td><?= fmt_date($l['fecha_inicio']) ?></td>
          <td><?= $l['fecha_fin'] ? fmt_date($l['fecha_fin']) : '<span class="dpr-text-muted">Indefinido</span>' ?></td>
          <td><?= fmt_money($l['valor_mensual']) ?></td>
          <td>
            <span title="Día del mes en que vence el pago adelantado">
              Día <?= max(1,(int)$l['dia_vencimiento'] - 5) ?>
              <span class="dpr-text-muted" style="font-size:11px">(inicio: <?= (int)$l['dia_vencimiento'] ?>)</span>
            </span>
          </td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst($l['estado']) ?></span></td>
          <td class="dpr-flex">
            <?php if ($l['estado'] === 'activo'): ?>
            <a href="?action=edit&id=<?= $l['id'] ?>" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Editar</a>
            <form method="POST" style="display:inline" onsubmit="return confirm('¿Terminar este contrato? La unidad quedará disponible.')">
              <input type="hidden" name="action" value="terminate">
              <input type="hidden" name="id"     value="<?= $l['id'] ?>">
              <button class="dpr-btn dpr-btn--sm dpr-btn--danger">Terminar</button>
            </form>
            <?php else: ?>
            <span class="dpr-text-muted dpr-btn--sm">Finalizado</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
