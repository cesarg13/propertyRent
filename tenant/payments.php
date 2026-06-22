<?php
// =============================================================
//  PropertyRent — Inquilino: Mis Pagos (Ledger)
//  Muestra todas las obligaciones activas del contrato (alquiler,
//  servicios, multas, etc.) y permite reportar un pago — un solo
//  monto + comprobante + nota. El admin decide a qué obligación(es)
//  se aplica al aprobar (ver admin/payments_admin2.php).
//
//  Usa el modelo nuevo: obligations / payments_received /
//  payment_applications. No toca payments / payment_partials.
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user = dpr_current_user();
$msg  = $type = '';
$CONCEPTOS = dpr_conceptos_ledger();

// Contrato activo
$lease = db_query($mysqli,
    "SELECT l.*, un.nombre AS unidad, pr.nombre AS inmueble
     FROM leases l
     JOIN units un ON un.id = l.unit_id
     JOIN properties pr ON pr.id = un.property_id
     WHERE l.tenant_id = ? AND l.estado = 'activo' LIMIT 1",
    'i', [$user['id']]);
$lease = $lease[0] ?? null;

// ---------------------------------------------------------------
// POST: reportar pago
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lease) {
    $act = $_POST['action'] ?? '';

    if ($act === 'reportar_pago') {
        $monto  = (float)str_replace(['.', '$', ','], ['', '', ''], $_POST['monto'] ?? '0');
        $metodo = $_POST['metodo'] ?? 'transferencia';
        $ref    = trim($_POST['referencia'] ?? '');
        $nota   = trim($_POST['nota'] ?? '');
        $fecha  = $_POST['fecha_pago'] ?: date('Y-m-d');

        if ($monto <= 0) {
            $msg = 'El monto debe ser mayor a cero.'; $type = 'danger';
        } elseif (empty($_FILES['comprobante']['name'])) {
            $msg = 'Debes adjuntar el comprobante del pago.'; $type = 'danger';
        } else {
            $up = dpr_upload_file($_FILES['comprobante'], 'comprobantes');
            if (!$up['success']) {
                $msg = $up['error']; $type = 'danger';
            } else {
                // Sin aplicaciones: el inquilino solo reporta el pago, queda
                // en 'validando'. El admin decide a qué obligación(es) se
                // aplica al aprobarlo — ver dpr_aprobar_pago_ledger().
                $r = dpr_registrar_pago_ledger(
                    $mysqli, $lease['id'], $monto, $metodo, $ref, $up['url'], $nota, $fecha,
                    $user['id'], 'tenant', []
                );
                if ($r['success']) {
                    $msg = 'Pago reportado. El administrador lo revisará pronto.'; $type = 'success';
                } else {
                    $msg = $r['error'] ?? 'No se pudo reportar el pago.'; $type = 'danger';
                }
            }
        }
    }
}

// ---------------------------------------------------------------
// Datos para la vista
// ---------------------------------------------------------------
$obligaciones = $lease ? dpr_obligaciones_lease_detalle($mysqli, $lease['id']) : [];
$historial_pagos = $lease ? dpr_pagos_lease_historial($mysqli, $lease['id'], 20) : [];
$saldo = $lease ? dpr_saldo_lease($mysqli, $lease['id']) : ['total_obligado' => 0, 'total_pagado' => 0, 'saldo_pendiente' => 0];

$page_title  = 'Mis Pagos';
$active_menu = 'payments';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Mis pagos</div>
    <div class="dpr-page-sub">
      <?= $lease ? h($lease['inmueble'] . ' · ' . $lease['unidad']) : 'Sin contrato activo' ?>
    </div>
  </div>
  <?php if ($lease): ?>
  <button type="button" class="dpr-btn dpr-btn--primary" onclick="openReportarPago()">Reportar pago</button>
  <?php endif; ?>
</div>

<?php if (!$lease): ?>
<div class="dpr-alert dpr-alert--info">No tienes un contrato activo. Contacta al administrador.</div>
<?php else:
    $sp = (float)$saldo['saldo_pendiente'];
?>

<!-- RESUMEN DE SALDO -->
<div class="dpr-card" style="background:#f8fafc">
  <div class="dpr-flex" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div><span class="dpr-text-muted">Total obligado:</span> <strong><?= fmt_money((float)$saldo['total_obligado']) ?></strong></div>
    <div><span class="dpr-text-muted">Total pagado:</span> <strong style="color:#059669"><?= fmt_money((float)$saldo['total_pagado']) ?></strong></div>
    <div>
      <span class="dpr-text-muted">Tu saldo:</span>
      <strong style="color:<?= $sp > 0 ? '#dc2626' : ($sp < 0 ? '#2563eb' : '#059669') ?>">
        <?= $sp == 0 ? 'Al día' : ($sp > 0 ? fmt_money($sp) . ' por pagar' : fmt_money(abs($sp)) . ' a tu favor') ?>
      </strong>
    </div>
  </div>
</div>

<!-- TABLA DE OBLIGACIONES -->
<div class="dpr-card">
  <div class="dpr-card__title">Mis obligaciones</div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr><th>Concepto</th><th>Periodo</th><th>Valor</th><th>Pagado</th><th>Pendiente</th><th>Fecha límite</th><th>Estado</th></tr>
      </thead>
      <tbody>
        <?php if (!$obligaciones): ?>
        <tr><td colspan="7" class="dpr-text-muted" style="text-align:center;padding:24px">Sin obligaciones registradas todavía.</td></tr>
        <?php endif; ?>
        <?php foreach ($obligaciones as $o):
            $concepto_label = $CONCEPTOS[$o['concepto']] ?? $o['concepto'];
            $pendiente = (float)$o['pendiente'];
            if ($pendiente <= 0)        { $pill = 'ok';   $estado_label = 'Pagada'; }
            elseif ($o['aplicado'] > 0) { $pill = 'info';  $estado_label = 'Parcial'; }
            elseif ($o['fecha_limite'] && strtotime($o['fecha_limite']) < strtotime(date('Y-m-d'))) { $pill = 'danger'; $estado_label = 'Vencida'; }
            else                         { $pill = 'neutral'; $estado_label = 'Pendiente'; }
        ?>
        <tr>
          <td><?= h($concepto_label) ?></td>
          <td><?= h($o['periodo'] ?? '—') ?></td>
          <td><?= fmt_money((float)$o['valor_efectivo']) ?></td>
          <td><?= $o['aplicado'] > 0 ? '<span style="color:#059669">' . fmt_money((float)$o['aplicado']) . '</span>' : '—' ?></td>
          <td><?= $pendiente > 0 ? '<strong>' . fmt_money($pendiente) . '</strong>' : '<span style="color:#059669">✓</span>' ?></td>
          <td><?= fmt_date($o['fecha_limite']) ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= h($estado_label) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- HISTORIAL DE PAGOS REPORTADOS -->
<?php if ($historial_pagos): ?>
<div class="dpr-card">
  <div class="dpr-card__title">Pagos reportados</div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead><tr><th>Fecha</th><th>Monto</th><th>Método</th><th>Referencia</th><th>Estado</th><th>Comprobante</th></tr></thead>
      <tbody>
        <?php foreach ($historial_pagos as $p):
            $pill = $p['estado'] === 'aprobado' ? 'ok' : ($p['estado'] === 'validando' ? 'warn' : 'danger');
            $estado_label = ['aprobado' => 'Aprobado', 'validando' => 'En revisión', 'rechazado' => 'Rechazado'][$p['estado']] ?? ucfirst($p['estado']);
        ?>
        <tr>
          <td><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
          <td><strong><?= fmt_money((float)$p['monto_total']) ?></strong></td>
          <td><?= ucfirst($p['metodo']) ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= h($p['referencia'] ?? '—') ?></td>
          <td>
            <span class="dpr-pill dpr-pill--<?= $pill ?>"><?= h($estado_label) ?></span>
            <?php if ($p['estado'] === 'rechazado' && $p['nota_admin']): ?>
            <div style="font-size:11px;color:#dc2626;margin-top:4px"><?= h($p['nota_admin']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= $p['comprobante_url'] ? '<a href="javascript:void(0)" onclick="verComprobante(\''.h(addslashes($p['comprobante_url'])).'\')" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Ver</a>' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="dpr-card" style="background:#fafafa;border-style:dashed">
  <div style="font-size:13px;color:var(--text-muted)">
    <strong style="color:var(--navy)">Importante:</strong>
    Al reportar un pago, el servidor registra la fecha y hora exacta de recepción.
    Este registro es inmutable y sirve como prueba de que enviaste el pago a tiempo.
    El administrador revisará tu comprobante y aplicará el pago a tus obligaciones pendientes.
    Si pagaste de más, el excedente queda registrado a tu favor.
  </div>
</div>

<!-- Modal: Reportar pago -->
<div class="dpr-modal-backdrop" id="reportarModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('reportarModal')">&times;</button>
    <div class="dpr-modal__title">Reportar pago</div>
    <?php if ($sp > 0): ?>
    <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px">
      Tu saldo pendiente actual es <strong><?= fmt_money($sp) ?></strong>.
    </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="reportar_pago">
      <div class="dpr-form-grid">
        <div class="dpr-form-group">
          <label class="dpr-label">Monto pagado ($)</label>
          <input type="number" name="monto" class="dpr-input" min="100" step="1" required
            placeholder="Ej: 500000" <?= $sp > 0 ? 'value="' . (int)$sp . '"' : '' ?>>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Método de pago</label>
          <select name="metodo" class="dpr-select">
            <?php foreach (['transferencia','nequi','daviplata','pse','efectivo','tarjeta','otro'] as $m): ?>
            <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Fecha del pago</label>
          <input type="date" name="fecha_pago" class="dpr-input" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Referencia / No. transacción</label>
          <input type="text" name="referencia" class="dpr-input" placeholder="No. comprobante o transacción">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Adjuntar comprobante</label>
          <div class="dpr-dropzone" onclick="document.getElementById('fiComp').click()"
               ondragover="this.classList.add('dragover');event.preventDefault()"
               ondragleave="this.classList.remove('dragover')"
               ondrop="handleDrop(event,'fiComp','fpComp')">
            <div class="dpr-dropzone__text">Arrastra tu archivo o haz clic aquí</div>
            <div style="font-size:12px;color:var(--text-muted)">PNG, JPG, WEBP o PDF · Máx 5 MB</div>
            <div id="fpComp" style="margin-top:8px;font-size:13px;color:var(--blue)"></div>
          </div>
          <input type="file" id="fiComp" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"
            style="display:none" onchange="showPrev(this,'fpComp')" required>
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Nota (banco, referencia, fecha del pago)</label>
          <textarea name="nota" class="dpr-textarea" style="height:55px"
            placeholder="Ej: Transferencia Bancolombia 13/04/2026 ref. 987654"></textarea>
        </div>
      </div>
      <div class="dpr-alert dpr-alert--info" style="font-size:12px;margin-top:10px">
        No necesitas indicar a qué período o concepto corresponde — el administrador lo asignará al revisar tu comprobante.
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('reportarModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Enviar reporte de pago</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Ver comprobante -->
<div class="dpr-modal-backdrop" id="comprobanteModal">
  <div class="dpr-modal" style="max-width:680px">
    <button class="dpr-modal__close" onclick="closeM('comprobanteModal')">&times;</button>
    <div class="dpr-modal__title">Comprobante</div>
    <div id="comprobanteBody" style="text-align:center"></div>
    <div class="dpr-form-actions" style="justify-content:flex-end">
      <a id="comprobanteDescargar" href="#" target="_blank" class="dpr-btn dpr-btn--secondary">Abrir en pestaña nueva</a>
    </div>
  </div>
</div>

<?php endif; // end if $lease ?>

<script>
function openReportarPago() {
  document.getElementById('reportarModal').classList.add('open');
}
function closeM(id) { document.getElementById(id).classList.remove('open'); }

// Abre el comprobante en el modal en vez de navegar a una pestaña nueva.
function verComprobante(url) {
  if (!url) return;
  const body = document.getElementById('comprobanteBody');
  const esPdf = /\.pdf(\?|$)/i.test(url);
  if (esPdf) {
    body.innerHTML = '<iframe src="' + url + '" style="width:100%;height:70vh;border:0.5px solid var(--border);border-radius:8px"></iframe>';
  } else {
    body.innerHTML = '<img src="' + url + '" style="max-width:100%;max-height:70vh;border-radius:8px;object-fit:contain">';
  }
  document.getElementById('comprobanteDescargar').href = url;
  document.getElementById('comprobanteModal').classList.add('open');
}
function showPrev(inp, previewId) {
  document.getElementById(previewId).textContent =
    inp.files[0] ? 'Seleccionado: ' + inp.files[0].name : '';
}
function handleDrop(e, inputId, previewId) {
  e.preventDefault(); e.currentTarget.classList.remove('dragover');
  if (e.dataTransfer.files.length) {
    document.getElementById(inputId).files = e.dataTransfer.files;
    showPrev(document.getElementById(inputId), previewId);
  }
}
document.querySelectorAll('.dpr-modal-backdrop').forEach(function(el) {
  el.addEventListener('click', function(e) { if (e.target === el) el.classList.remove('open'); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
