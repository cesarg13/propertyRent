<?php
// =============================================================
//  PropertyRent — Inquilino: Soporte / Tickets
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user = dpr_current_user();
$msg  = $type = '';

$lease = db_query($mysqli,
    "SELECT l.id, l.unit_id FROM leases l WHERE l.tenant_id=? AND l.estado='activo' LIMIT 1",
    'i', [$user['id']]);
$lease = $lease[0] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create_ticket') {
    $categoria   = $_POST['categoria']   ?? 'otro';
    $asunto      = trim($_POST['asunto'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $prioridad   = $_POST['prioridad']   ?? 'media';

    if (!$asunto || !$descripcion) {
        $msg = 'Asunto y descripción son obligatorios.'; $type = 'danger';
    } else {
        db_execute($mysqli,
            "INSERT INTO support_tickets (tenant_id,unit_id,categoria,asunto,descripcion,prioridad)
             VALUES (?,?,?,?,?,?)",
            'iissss', [$user['id'], $lease['unit_id'] ?? null, $categoria, $asunto, $descripcion, $prioridad]);
        $msg = 'Solicitud enviada. El administrador te responderá pronto.'; $type = 'success';
    }
}

$tickets = db_query($mysqli,
    "SELECT * FROM support_tickets WHERE tenant_id=? ORDER BY created_at DESC",
    'i', [$user['id']]);

$page_title  = 'Soporte';
$active_menu = 'support';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?><div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Soporte y solicitudes</div>
    <div class="dpr-page-sub">Crea un ticket y el administrador te responderá</div>
  </div>
</div>

<div class="dpr-grid-2">
  <!-- Formulario nuevo ticket -->
  <div class="dpr-card">
    <div class="dpr-card__title">Nueva solicitud</div>
    <form method="POST">
      <input type="hidden" name="action" value="create_ticket">
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Categoría</label>
        <select name="categoria" class="dpr-select">
          <option value="mantenimiento">Mantenimiento / Reparación</option>
          <option value="consulta_pago">Consulta de pago</option>
          <option value="documentos">Documentos</option>
          <option value="otro">Otro</option>
        </select>
      </div>
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Prioridad</label>
        <select name="prioridad" class="dpr-select">
          <option value="baja">Baja</option>
          <option value="media" selected>Media</option>
          <option value="alta">Alta</option>
          <option value="urgente">Urgente</option>
        </select>
      </div>
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Asunto *</label>
        <input type="text" name="asunto" class="dpr-input" required maxlength="200"
          placeholder="Describe brevemente el problema">
      </div>
      <div class="dpr-form-group" style="margin-bottom:16px">
        <label class="dpr-label">Descripción detallada *</label>
        <textarea name="descripcion" class="dpr-textarea" required style="height:100px"
          placeholder="Describe el problema con el mayor detalle posible..."></textarea>
      </div>
      <button type="submit" class="dpr-btn dpr-btn--primary">Enviar solicitud</button>
    </form>
  </div>

  <!-- Historial de tickets -->
  <div class="dpr-card">
    <div class="dpr-card__title">Mis solicitudes anteriores</div>
    <?php if (!$tickets): ?>
    <div class="dpr-text-muted" style="text-align:center;padding:24px">Aún no has creado solicitudes.</div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php foreach ($tickets as $t):
        $spill = match($t['estado']) { 'abierto'=>'info','en_proceso'=>'warn','resuelto'=>'ok','cerrado'=>'neutral', default=>'neutral' };
        $ppill = match($t['prioridad']) { 'urgente'=>'danger','alta'=>'warn','media'=>'info', default=>'neutral' };
      ?>
      <div style="border:0.5px solid var(--border);border-radius:var(--radius);padding:14px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
          <div style="font-size:13px;font-weight:600;color:var(--navy)"><?= h($t['asunto']) ?></div>
          <div class="dpr-flex">
            <span class="dpr-pill dpr-pill--<?= $ppill ?>"><?= ucfirst($t['prioridad']) ?></span>
            <span class="dpr-pill dpr-pill--<?= $spill ?>"><?= ucfirst(str_replace('_',' ',$t['estado'])) ?></span>
          </div>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">
          <?= fmt_date($t['created_at']) ?> · <?= ucfirst($t['categoria']) ?>
        </div>
        <?php if ($t['respuesta']): ?>
        <div style="background:#f0f9ff;border-left:3px solid #0284c7;padding:8px 12px;border-radius:0 6px 6px 0;font-size:12px;margin-top:8px">
          <strong style="color:#0c4a6e">Respuesta del administrador:</strong><br>
          <?= h($t['respuesta']) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
