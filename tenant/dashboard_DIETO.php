<?php
// =============================================================
//  PropertyRent — Dashboard del Inquilino
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user    = dpr_current_user();
$periodo = date('Y-m');

// Contrato activo del inquilino
$lease = db_query($mysqli,
    "SELECT l.*, un.nombre AS unidad, un.valor_arriendo,
            pr.nombre AS inmueble, pr.ciudad,
            (l.dia_vencimiento - 5) AS dia_pago
     FROM leases l
     JOIN units un      ON un.id = l.unit_id
     JOIN properties pr ON pr.id = un.property_id
     WHERE l.tenant_id = ? AND l.estado = 'activo'
     LIMIT 1", 'i', [$user['id']]);
$lease = $lease[0] ?? null;

// Pago del período actual
$pago_actual = null;
$mora_info   = ['dias_mora'=>0,'valor_mora'=>0,'tasa'=>0];
if ($lease) {
    $rows = db_query($mysqli,
        "SELECT * FROM payments WHERE lease_id=? AND periodo=? LIMIT 1",
        'is', [$lease['id'], $periodo]);
    $pago_actual = $rows[0] ?? null;

    if ($pago_actual && !in_array($pago_actual['estado'], ['pagado','condonado'])) {
        $mora_info = dpr_calcular_mora($mysqli, $lease['id'],
            (float)$lease['valor_arriendo'], $pago_actual['fecha_vencimiento']);
    }
}

// Días para el vencimiento
$dias_restantes = null;
$vence_pronto   = false;
if ($pago_actual) {
    $hoy  = new DateTime();
    $venc = new DateTime($pago_actual['fecha_vencimiento']);
    $diff = (int)$hoy->diff($venc)->days;
    $dias_restantes = $hoy <= $venc ? $diff : -$diff;
    $vence_pronto = $dias_restantes >= 0 && $dias_restantes <= 5;
}

// Historial de pagos (últimos 12)
$historial = $lease ? db_query($mysqli,
    "SELECT * FROM payments WHERE lease_id=? ORDER BY periodo DESC LIMIT 12",
    'i', [$lease['id']]) : [];

// Servicios públicos pendientes
$servicios_pendientes = $lease ? db_query($mysqli,
    "SELECT * FROM public_services WHERE lease_id=? AND estado='pendiente' AND periodo=?",
    'is', [$lease['id'], $periodo]) : [];

$page_title  = 'Mi cuenta';
$active_menu = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Bienvenido, <?= h(explode(' ',$user['nombre'])[0]) ?></div>
    <div class="dpr-page-sub">
      <?= $lease ? h($lease['inmueble'].' · '.$lease['unidad'].' · '.$lease['ciudad']) : 'Sin contrato activo' ?>
    </div>
  </div>
  <?php if ($pago_actual): ?>
    <?php if ($mora_info['valor_mora'] > 0): ?>
      <span class="dpr-pill dpr-pill--danger" style="font-size:13px;padding:6px 14px">En mora</span>
    <?php elseif ($vence_pronto): ?>
      <span class="dpr-pill dpr-pill--warn" style="font-size:13px;padding:6px 14px">Vence en <?= $dias_restantes ?> día<?= $dias_restantes!=1?'s':'' ?></span>
    <?php elseif ($pago_actual['estado'] === 'pagado'): ?>
      <span class="dpr-pill dpr-pill--ok" style="font-size:13px;padding:6px 14px">Al día</span>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (!$lease): ?>
<div class="dpr-alert dpr-alert--info">No tienes un contrato activo. Contacta al administrador.</div>
<?php else: ?>

<?php // ---- ALERTA DE MORA ---- ?>
<?php if ($mora_info['valor_mora'] > 0): ?>
<div class="dpr-alert dpr-alert--danger">
  <svg width="18" height="18" viewBox="0 0 18 18" fill="none" style="flex-shrink:0"><circle cx="9" cy="9" r="8" stroke="currentColor" stroke-width="1.4"/><path d="M9 5v4M9 11.5v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
  Tienes <strong><?= $mora_info['dias_mora'] ?> días de mora</strong> en el período <?= h($periodo) ?>.
  Interés acumulado: <strong><?= fmt_money($mora_info['valor_mora']) ?></strong> (tasa <?= $mora_info['tasa'] ?>% mensual).
  El valor total a pagar es <strong><?= fmt_money(($pago_actual['valor_arriendo'] ?? 0) + $mora_info['valor_mora']) ?></strong>.
</div>
<?php elseif ($vence_pronto && $pago_actual['estado'] !== 'pagado'): ?>
<div class="dpr-alert dpr-alert--warn">
  <svg width="18" height="18" viewBox="0 0 18 18" fill="none" style="flex-shrink:0"><circle cx="9" cy="9" r="8" stroke="currentColor" stroke-width="1.4"/><path d="M9 5v4M9 11.5v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
  Tu pago del periodo <strong><?= h($periodo) ?></strong> vence el <strong><?= fmt_date($pago_actual['fecha_vencimiento']) ?></strong>.
  Tienes <strong><?= $dias_restantes ?> día<?= $dias_restantes!=1?'s':'' ?></strong> para pagar sin mora.
</div>
<?php endif; ?>

<?php // ---- TARJETA ESTADO DE CUENTA ---- ?>
<div class="dpr-grid-2" style="margin-bottom:18px">
  <div class="dpr-card">
    <div class="dpr-card__title">Estado de cuenta · <?= date('F Y') ?></div>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:18px">
      <div style="display:flex;justify-content:space-between;font-size:14px">
        <span class="dpr-text-muted">Arriendo mensual</span>
        <strong><?= fmt_money($lease['valor_arriendo']) ?></strong>
      </div>
      <?php if ($mora_info['valor_mora'] > 0): ?>
      <div style="display:flex;justify-content:space-between;font-size:14px">
        <span style="color:#991b1b">Interés de mora (<?= $mora_info['dias_mora'] ?> días)</span>
        <strong style="color:#dc2626"><?= fmt_money($mora_info['valor_mora']) ?></strong>
      </div>
      <?php endif; ?>
      <hr class="dpr-divider" style="margin:4px 0">
      <div style="display:flex;justify-content:space-between;font-size:16px">
        <span style="font-weight:600">Total a pagar</span>
        <strong style="color:var(--navy);font-size:18px"><?= fmt_money(($lease['valor_arriendo']) + $mora_info['valor_mora']) ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px">
        <span class="dpr-text-muted">Fecha límite de pago</span>
        <span><?= $pago_actual ? fmt_date($pago_actual['fecha_vencimiento']) : '—' ?></span>
      </div>
    </div>
    <?php if ($pago_actual && !in_array($pago_actual['estado'],['pagado','condonado','validando'])): ?>
    <div style="display:flex;gap:10px">
      <button class="dpr-btn dpr-btn--primary" style="flex:1;justify-content:center"
        onclick="document.getElementById('uploadModal').classList.add('open')">
        Subir comprobante
      </button>
    </div>
    <?php elseif ($pago_actual && $pago_actual['estado'] === 'validando'): ?>
    <div class="dpr-alert dpr-alert--warn" style="margin-bottom:0">Tu comprobante está siendo revisado por el administrador.</div>
    <?php elseif ($pago_actual && $pago_actual['estado'] === 'pagado'): ?>
    <div class="dpr-alert dpr-alert--success" style="margin-bottom:0">
      Pago confirmado el <?= fmt_date($pago_actual['fecha_pago']) ?>. 
      <?php if ($pago_actual['comprobante_url']): ?>
      <a href="<?= h($pago_actual['comprobante_url']) ?>" target="_blank" style="color:var(--success-tx);font-weight:500">Ver comprobante</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="dpr-card">
    <div class="dpr-card__title">Datos de mi contrato</div>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
      <div style="display:flex;justify-content:space-between">
        <span class="dpr-text-muted">Inmueble</span><span><?= h($lease['inmueble']) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between">
        <span class="dpr-text-muted">Unidad</span><span><?= h($lease['unidad']) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between">
        <span class="dpr-text-muted">Inicio contrato</span><span><?= fmt_date($lease['fecha_inicio']) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between">
        <span class="dpr-text-muted">Fin contrato</span>
        <span><?= $lease['fecha_fin'] ? fmt_date($lease['fecha_fin']) : 'Indefinido' ?></span>
      </div>
      <div style="display:flex;justify-content:space-between">
        <span class="dpr-text-muted">Día de pago (límite)</span>
        <span>Día <strong><?= (int)$lease['dia_pago'] ?></strong> de cada mes</span>
      </div>
      <div style="display:flex;justify-content:space-between">
        <span class="dpr-text-muted">Depósito</span>
        <span><?= $lease['deposito'] ? fmt_money($lease['deposito']) : '—' ?></span>
      </div>
    </div>
  </div>
</div>

<?php // ---- SERVICIOS PENDIENTES ---- ?>
<?php if ($servicios_pendientes): ?>
<div class="dpr-card">
  <div class="dpr-card__title">Servicios públicos pendientes · <?= h($periodo) ?></div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead><tr><th>Servicio</th><th>Valor</th><th>Estado</th></tr></thead>
      <tbody>
        <?php foreach ($servicios_pendientes as $s): ?>
        <tr>
          <td><?= ucfirst($s['tipo']) ?></td>
          <td><?= fmt_money($s['valor']) ?></td>
          <td><span class="dpr-pill dpr-pill--warn">Pendiente</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php // ---- HISTORIAL DE PAGOS ---- ?>
<div class="dpr-card">
  <div class="dpr-card__title">Historial de pagos</div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr><th>Periodo</th><th>Arriendo</th><th>Mora</th><th>Total</th><th>Fecha pago</th><th>Método</th><th>Estado</th><th>Comprobante</th></tr>
      </thead>
      <tbody>
        <?php foreach ($historial as $h_pay):
          $pill = match($h_pay['estado']) {
            'pagado'    => 'ok',
            'validando' => 'warn',
            'moroso'    => 'danger',
            'pendiente' => 'info',
            default     => 'neutral',
          };
        ?>
        <tr>
          <td><?= h($h_pay['periodo']) ?></td>
          <td><?= fmt_money($h_pay['valor_arriendo']) ?></td>
          <td><?= $h_pay['valor_mora'] > 0 ? '<span style="color:#dc2626">'.fmt_money($h_pay['valor_mora']).'</span>' : '—' ?></td>
          <td><strong><?= fmt_money($h_pay['valor_total']) ?></strong></td>
          <td><?= $h_pay['fecha_pago'] ? fmt_date($h_pay['fecha_pago']) : '—' ?></td>
          <td><?= $h_pay['metodo'] ? ucfirst($h_pay['metodo']) : '—' ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst($h_pay['estado']) ?></span></td>
          <td>
            <?php if ($h_pay['comprobante_url']): ?>
            <a href="<?= h($h_pay['comprobante_url']) ?>" target="_blank" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Descargar</a>
            <?php elseif (in_array($h_pay['estado'],['pendiente','moroso'])): ?>
            <button class="dpr-btn dpr-btn--sm dpr-btn--primary"
              onclick="openUploadForPeriod('<?= h($h_pay['id']) ?>','<?= h($h_pay['periodo']) ?>')">
              Subir
            </button>
            <?php else: ?>
            <span class="dpr-text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$historial): ?>
        <tr><td colspan="8" class="dpr-text-muted" style="text-align:center;padding:24px">Sin historial de pagos.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Subir comprobante -->
<div class="dpr-modal-backdrop" id="uploadModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="document.getElementById('uploadModal').classList.remove('open')">&times;</button>
    <div class="dpr-modal__title">Subir comprobante de pago</div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px">
      El administrador revisará tu comprobante en máximo 24 horas hábiles. 
      Una vez aprobado, quedará registrado en el sistema con la fecha y hora de recepción.
    </p>
    <form method="POST" action="payments.php" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="upload_comprobante">
      <input type="hidden" name="payment_id" id="uploadPayId"
        value="<?= $pago_actual ? $pago_actual['id'] : '' ?>">
      <div class="dpr-dropzone" onclick="document.getElementById('fileInput').click()"
           ondragover="this.classList.add('dragover');event.preventDefault()"
           ondragleave="this.classList.remove('dragover')"
           ondrop="handleDrop(event)">
        <div class="dpr-dropzone__text">Arrastra tu archivo aquí o haz clic para seleccionar</div>
        <div style="font-size:12px;color:var(--text-muted)">PNG, JPG, WEBP o PDF · Máximo 5 MB</div>
        <div id="filePreview" style="margin-top:10px;font-size:13px;color:var(--blue)"></div>
      </div>
      <input type="file" id="fileInput" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"
        style="display:none" onchange="showPreview(this)">
      <div class="dpr-form-group" style="margin-top:14px">
        <label class="dpr-label">Nota opcional (banco, referencia, fecha real del pago)</label>
        <textarea name="nota_inquilino" class="dpr-textarea" style="height:60px"
          placeholder="Ej: Transferencia Bancolombia del 13/04/2026 ref. 987654"></textarea>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary"
          onclick="document.getElementById('uploadModal').classList.remove('open')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Enviar comprobante</button>
      </div>
    </form>
  </div>
</div>

<?php endif; // end if $lease ?>

<script>
function openUploadForPeriod(payId, periodo) {
  document.getElementById('uploadPayId').value = payId;
  document.getElementById('uploadModal').classList.add('open');
}
function showPreview(input) {
  const name = input.files[0]?.name ?? '';
  document.getElementById('filePreview').textContent = name ? 'Archivo seleccionado: ' + name : '';
}
function handleDrop(e) {
  e.preventDefault(); e.currentTarget.classList.remove('dragover');
  const dt = e.dataTransfer;
  if (dt.files.length) {
    document.getElementById('fileInput').files = dt.files;
    showPreview(document.getElementById('fileInput'));
  }
}
document.getElementById('uploadModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
