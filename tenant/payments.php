<?php
// =============================================================
//  PropertyRent — Inquilino: Mis Pagos
//  Soporta: subir comprobante, abonos parciales, saldo a favor
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user = dpr_current_user();
$msg  = $type = '';

// Detectar si la migración de pagos parciales está aplicada
$_col        = $mysqli->query("SHOW COLUMNS FROM payments LIKE 'monto_pagado'");
$has_parcial = $_col && $_col->num_rows > 0;

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
// POST
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lease) {
    $act        = $_POST['action']     ?? '';
    $payment_id = (int)($_POST['payment_id'] ?? 0);

    // --- Subir comprobante de pago total ---
    if ($act === 'upload_comprobante' && $payment_id) {
        $own = db_query($mysqli,
            "SELECT id, estado FROM payments WHERE id = ? AND lease_id = ?",
            'ii', [$payment_id, $lease['id']]);

        if (!$own || in_array($own[0]['estado'], ['pagado', 'condonado'])) {
            $msg = 'No puedes subir comprobante para este período.'; $type = 'danger';
        } elseif ($own[0]['estado'] === 'validando') {
            $msg = 'Ya enviaste un comprobante para este período. Espera la revisión del administrador.'; $type = 'warn';
        } elseif (empty($_FILES['comprobante']['name'])) {
            $msg = 'Debes seleccionar un archivo.'; $type = 'danger';
        } else {
            $up = dpr_upload_file($_FILES['comprobante'], 'comprobantes');
            if (!$up['success']) {
                $msg = $up['error']; $type = 'danger';
            } else {
                db_execute($mysqli,
                    "UPDATE payments SET estado='validando', comprobante_url=?, nota_inquilino=?, updated_at=NOW() WHERE id=?",
                    'ssi', [$up['url'], trim($_POST['nota_inquilino'] ?? ''), $payment_id]);
                dpr_audit_log($mysqli, $user['id'], 'UPLOAD', 'payments', $payment_id, "Subió comprobante: {$up['filename']}");
                $msg = 'Comprobante enviado. El administrador lo revisará pronto.'; $type = 'success';
            }
        }
    }

    // --- Registrar abono parcial ---
    if ($act === 'abono' && $payment_id && $has_parcial) {
        $monto  = (float)($_POST['monto_abono'] ?? 0);
        $metodo = $_POST['metodo_abono'] ?? 'transferencia';
        $ref    = trim($_POST['ref_abono']  ?? '');
        $nota   = trim($_POST['nota_abono'] ?? '');

        $own = db_query($mysqli,
            "SELECT id, estado FROM payments WHERE id = ? AND lease_id = ?",
            'ii', [$payment_id, $lease['id']]);

        if (!$own || in_array($own[0]['estado'], ['pagado', 'condonado'])) {
            $msg = 'No puedes abonar a este período.'; $type = 'danger';
        } elseif ($monto <= 0) {
            $msg = 'El monto debe ser mayor a cero.'; $type = 'danger';
        } else {
            $comp_url = null;
            if (!empty($_FILES['comp_abono']['name'])) {
                $up = dpr_upload_file($_FILES['comp_abono'], 'comprobantes');
                if ($up['success']) $comp_url = $up['url'];
            }
            $r = dpr_registrar_abono($mysqli, $payment_id, $lease['id'], $monto,
                                     $metodo, $ref, $comp_url, $nota, $user['id'], 'tenant');
            if (!$r['success']) {
                $msg = $r['error']; $type = 'danger';
            } else {
                $msg = 'Abono registrado y enviado para revisión.';
                if (!empty($r['saldo_a_favor']) && $r['saldo_a_favor'] > 0) {
                    $msg .= ' Saldo a favor generado: ' . fmt_money($r['saldo_a_favor']) . '.';
                }
                $type = 'success';
            }
        }
    }
}

// Todos los pagos del contrato
$sel_extra = $has_parcial
    ? "p.monto_pagado, p.tiene_abonos, GREATEST(0, p.valor_total - p.monto_pagado) AS saldo_pendiente,"
    : "0 AS monto_pagado, 0 AS tiene_abonos, p.valor_total AS saldo_pendiente,";

$payments = $lease ? db_query($mysqli,
    "SELECT p.*, $sel_extra p.id AS pay_id
     FROM payments p WHERE p.lease_id = ? ORDER BY p.periodo DESC",
    'i', [$lease['id']]) : [];

foreach ($payments as &$p) {
    if (!in_array($p['estado'], ['pagado','condonado','validando']) && $lease) {
        $m = dpr_calcular_mora($mysqli, $lease['id'], (float)$p['valor_arriendo'], $p['fecha_vencimiento']);
        $p['mora_calc'] = $m['valor_mora'];
        $p['dias_mora'] = $m['dias_mora'];
    } else {
        $p['mora_calc'] = (float)$p['valor_mora'];
        $p['dias_mora'] = 0;
    }
}
unset($p);

// Historial de abonos si migración aplicada
$abonos = ($has_parcial && $lease) ? db_query($mysqli,
    "SELECT pp.*, pay.periodo FROM payment_partials pp
     JOIN payments pay ON pay.id = pp.payment_id
     WHERE pp.lease_id = ? ORDER BY pp.created_at DESC LIMIT 20",
    'i', [$lease['id']]) : [];

$saldo_favor = $has_parcial && $lease
    ? (float)(db_query($mysqli, "SELECT saldo_favor FROM leases WHERE id=?", 'i', [$lease['id']])[0]['saldo_favor'] ?? 0)
    : 0;

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
      <?php if ($saldo_favor > 0): ?>
        &nbsp;· <span class="dpr-pill dpr-pill--ok">Saldo a favor: <?= fmt_money($saldo_favor) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$lease): ?>
<div class="dpr-alert dpr-alert--info">No tienes un contrato activo. Contacta al administrador.</div>
<?php else: ?>

<!-- TABLA DE PAGOS -->
<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr>
          <th>Periodo</th><th>Arriendo</th><th>Mora</th><th>Total</th>
          <?php if ($has_parcial): ?><th>Abonado</th><th>Pendiente</th><?php endif; ?>
          <th>Vence</th><th>Estado</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $p):
            $estado = $p['estado'];
            $pill = 'neutral';
            if ($estado === 'pagado')    $pill = 'ok';
            elseif ($estado === 'validando') $pill = 'warn';
            elseif ($estado === 'moroso')   $pill = 'danger';
            elseif ($estado === 'pendiente') $pill = 'info';

            $total_real  = (float)$p['valor_arriendo'] + (float)$p['mora_calc'];
            $abonado     = (float)$p['monto_pagado'];
            $saldo_pend  = (float)$p['saldo_pendiente'];
            $puede_pagar = in_array($estado, ['pendiente', 'moroso']);
            $puede_abonar = $puede_pagar && $has_parcial;
        ?>
        <tr>
          <td>
            <strong><?= h($p['periodo']) ?></strong>
            <?php if (!empty($p['tiene_abonos'])): ?>
              <span class="dpr-pill dpr-pill--info" style="font-size:10px;margin-left:4px">Parcial</span>
            <?php endif; ?>
          </td>
          <td><?= fmt_money((float)$p['valor_arriendo']) ?></td>
          <td>
            <?php if ($p['mora_calc'] > 0): ?>
              <span style="color:#dc2626"><?= fmt_money($p['mora_calc']) ?></span>
              <?php if ($p['dias_mora'] > 0): ?>
                <span class="dpr-pill dpr-pill--danger" style="margin-left:3px;font-size:10px"><?= $p['dias_mora'] ?>d</span>
              <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><strong><?= fmt_money($total_real) ?></strong></td>
          <?php if ($has_parcial): ?>
          <td><?= $abonado > 0 ? '<span style="color:#059669">' . fmt_money($abonado) . '</span>' : '—' ?></td>
          <td><?= $saldo_pend > 0 ? '<span style="color:#d97706">' . fmt_money($saldo_pend) . '</span>' : '<span style="color:#059669">✓</span>' ?></td>
          <?php endif; ?>
          <td><?= fmt_date($p['fecha_vencimiento']) ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst($estado) ?></span></td>
          <td class="dpr-flex">
            <?php if ($p['comprobante_url'] && in_array($estado, ['pagado','validando'])): ?>
              <a href="<?= h($p['comprobante_url']) ?>" target="_blank" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Ver</a>
            <?php endif; ?>
            <?php if ($puede_pagar): ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--primary"
                onclick="openUpload(<?= (int)$p['id'] ?>,'<?= h($p['periodo']) ?>','<?= fmt_money($total_real) ?>')">
                Subir comprobante
              </button>
            <?php endif; ?>
            <?php if ($puede_abonar): ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--secondary"
                onclick="openAbono(<?= (int)$p['id'] ?>,'<?= h($p['periodo']) ?>','<?= fmt_money($saldo_pend > 0 ? $saldo_pend : $total_real) ?>')">
                + Abono
              </button>
            <?php endif; ?>
            <?php if ($estado === 'validando'): ?>
              <span class="dpr-pill dpr-pill--warn">En revisión…</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?>
        <tr><td colspan="<?= $has_parcial ? 9 : 7 ?>" class="dpr-text-muted" style="text-align:center;padding:24px">Sin historial de pagos.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($abonos): ?>
<div class="dpr-card">
  <div class="dpr-card__title">Historial de abonos</div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead><tr><th>Fecha</th><th>Periodo</th><th>Monto</th><th>Método</th><th>Referencia</th><th>Tipo</th><th>Archivo</th></tr></thead>
      <tbody>
        <?php foreach ($abonos as $a):
            $tpill = $a['tipo'] === 'saldo_favor_aplicado' ? 'ok' : 'info';
            $tlabel = $a['tipo'] === 'saldo_favor_aplicado' ? 'Saldo favor' : ucfirst(str_replace('_',' ',$a['tipo']));
        ?>
        <tr>
          <td><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
          <td><?= h($a['periodo']) ?></td>
          <td><strong><?= fmt_money((float)$a['monto']) ?></strong></td>
          <td><?= ucfirst($a['metodo'] ?? '—') ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= h($a['referencia'] ?? '—') ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $tpill ?>"><?= $tlabel ?></span></td>
          <td><?= $a['comprobante_url'] ? '<a href="'.h($a['comprobante_url']).'" target="_blank" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Ver</a>' : '—' ?></td>
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
    Al subir un comprobante o registrar un abono, el servidor registra la fecha y hora exacta de recepción.
    Este registro es inmutable y sirve como prueba de que enviaste el pago a tiempo.
    <?php if ($has_parcial): ?>
    Si realizas un pago que supera el total adeudado, el excedente queda como <strong>saldo a favor</strong>
    y se aplica automáticamente al siguiente período.
    <?php endif; ?>
  </div>
</div>

<!-- Modal: Subir comprobante -->
<div class="dpr-modal-backdrop" id="uploadModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('uploadModal')">&times;</button>
    <div class="dpr-modal__title">Subir comprobante de pago</div>
    <div id="uploadInfo" style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="upload_comprobante">
      <input type="hidden" name="payment_id" id="uploadPayId">
      <div class="dpr-dropzone" onclick="document.getElementById('fi1').click()"
           ondragover="this.classList.add('dragover');event.preventDefault()"
           ondragleave="this.classList.remove('dragover')"
           ondrop="handleDrop(event,'fi1','fp1')">
        <div class="dpr-dropzone__text">Arrastra tu archivo o haz clic aquí</div>
        <div style="font-size:12px;color:var(--text-muted)">PNG, JPG, WEBP o PDF · Máx 5 MB</div>
        <div id="fp1" style="margin-top:8px;font-size:13px;color:var(--blue)"></div>
      </div>
      <input type="file" id="fi1" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"
        style="display:none" onchange="showPrev(this,'fp1')" required>
      <div class="dpr-form-group" style="margin-top:14px;margin-bottom:0">
        <label class="dpr-label">Nota (banco, referencia, fecha del pago)</label>
        <textarea name="nota_inquilino" class="dpr-textarea" style="height:55px"
          placeholder="Ej: Transferencia Bancolombia 13/04/2026 ref. 987654"></textarea>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('uploadModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Enviar comprobante</button>
      </div>
    </form>
  </div>
</div>

<?php if ($has_parcial): ?>
<!-- Modal: Abono parcial -->
<div class="dpr-modal-backdrop" id="abonoModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('abonoModal')">&times;</button>
    <div class="dpr-modal__title">Registrar abono / pago parcial</div>
    <div id="abonoInfo" style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="abono">
      <input type="hidden" name="payment_id" id="abonoPayId">
      <div class="dpr-form-grid">
        <div class="dpr-form-group">
          <label class="dpr-label">Monto del abono ($)</label>
          <input type="number" name="monto_abono" class="dpr-input" min="100" step="1" required placeholder="Ej: 500000">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Método de pago</label>
          <select name="metodo_abono" class="dpr-select">
            <?php foreach (['transferencia','nequi','daviplata','pse','efectivo','tarjeta','otro'] as $m): ?>
            <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Referencia / No. transacción</label>
          <input type="text" name="ref_abono" class="dpr-input" placeholder="No. comprobante o transacción">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Adjuntar comprobante del abono</label>
          <div class="dpr-dropzone" onclick="document.getElementById('fi2').click()"
               ondragover="this.classList.add('dragover');event.preventDefault()"
               ondragleave="this.classList.remove('dragover')"
               ondrop="handleDrop(event,'fi2','fp2')" style="padding:12px">
            <div style="font-size:13px;color:var(--text-muted)">PNG, JPG, WEBP o PDF · Máx 5 MB</div>
            <div id="fp2" style="margin-top:6px;font-size:13px;color:var(--blue)"></div>
          </div>
          <input type="file" id="fi2" name="comp_abono" accept=".jpg,.jpeg,.png,.webp,.pdf"
            style="display:none" onchange="showPrev(this,'fp2')">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Nota adicional (opcional)</label>
          <textarea name="nota_abono" class="dpr-textarea" style="height:50px"></textarea>
        </div>
      </div>
      <div class="dpr-alert dpr-alert--info" style="font-size:12px;margin-top:10px">
        Si el abono supera el saldo adeudado, el excedente queda como <strong>saldo a favor</strong> para tu próximo período.
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('abonoModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Enviar abono</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php endif; // end if $lease ?>

<script>
function openUpload(id, periodo, monto) {
  document.getElementById('uploadPayId').value = id;
  document.getElementById('uploadInfo').innerHTML =
    'Periodo: <strong>' + periodo + '</strong> &nbsp;·&nbsp; Total: <strong>' + monto + '</strong>';
  document.getElementById('uploadModal').classList.add('open');
}
function openAbono(id, periodo, pendiente) {
  document.getElementById('abonoPayId').value = id;
  document.getElementById('abonoInfo').innerHTML =
    'Periodo: <strong>' + periodo + '</strong> &nbsp;·&nbsp; Pendiente: <strong>' + pendiente + '</strong>';
  document.getElementById('abonoModal').classList.add('open');
}
function closeM(id) { document.getElementById(id).classList.remove('open'); }
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
