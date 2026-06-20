<?php
// =============================================================
//  PropertyRent — Inquilino: Mis Pagos (con abonos parciales)
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user = dpr_current_user();
$msg  = $type = '';

$lease = db_query($mysqli,
    "SELECT l.*, un.nombre AS unidad, pr.nombre AS inmueble
     FROM leases l JOIN units un ON un.id=l.unit_id JOIN properties pr ON pr.id=un.property_id
     WHERE l.tenant_id=? AND l.estado='activo' LIMIT 1",
    'i', [$user['id']]);
$lease = $lease[0] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lease) {
    $act        = $_POST['action']     ?? '';
    $payment_id = (int)($_POST['payment_id'] ?? 0);

    // --- Subir comprobante de pago completo ---
    if ($act === 'upload_comprobante' && $payment_id) {
        $own = db_query($mysqli,"SELECT id,estado FROM payments WHERE id=? AND lease_id=?",'ii',[$payment_id,$lease['id']]);
        if (!$own || in_array($own[0]['estado'],['pagado','condonado'])) {
            $msg='No puedes subir comprobante para este período.';$type='danger';
        } elseif (empty($_FILES['comprobante']['name'])) {
            $msg='Debes seleccionar un archivo.';$type='danger';
        } else {
            $up = dpr_upload_image($_FILES['comprobante'],'comprobantes');
            if (!$up['success']) { $msg=$up['error'];$type='danger'; }
            else {
                db_execute($mysqli,"UPDATE payments SET estado='validando',comprobante_url=?,nota_inquilino=?,updated_at=NOW() WHERE id=?",'ssi',[$up['url'],$_POST['nota_inquilino']??'',$payment_id]);
                dpr_audit_log($mysqli,$user['id'],'UPLOAD','payments',$payment_id,"Inquilino subió comprobante");
                $msg='Comprobante enviado. El administrador lo revisará pronto.';$type='success';
            }
        }
    }

    // --- Registrar abono parcial (inquilino) ---
    if ($act === 'abono' && $payment_id) {
        $monto  = (float)($_POST['monto_abono'] ?? 0);
        $metodo = $_POST['metodo_abono'] ?? 'transferencia';
        $ref    = trim($_POST['ref_abono'] ?? '');
        $nota   = trim($_POST['nota_abono'] ?? '');
        $own    = db_query($mysqli,"SELECT id,estado FROM payments WHERE id=? AND lease_id=?",'ii',[$payment_id,$lease['id']]);
        if (!$own || in_array($own[0]['estado'],['pagado','condonado'])) {
            $msg='No puedes abonar a este período.';$type='danger';
        } elseif ($monto <= 0) {
            $msg='El monto debe ser mayor a cero.';$type='danger';
        } else {
            $comp_url = null;
            if (!empty($_FILES['comp_abono']['name'])) {
                $up = dpr_upload_image($_FILES['comp_abono'],'comprobantes');
                if ($up['success']) $comp_url = $up['url'];
            }
            $r = dpr_registrar_abono($mysqli,$payment_id,$lease['id'],$monto,$metodo,$ref,$comp_url,$nota,$user['id'],'tenant');
            $msg  = $r['success'] ? 'Abono registrado correctamente.' : $r['error'];
            if ($r['success'] && $r['saldo_a_favor'] > 0) $msg .= ' Se generó saldo a favor de '.fmt_money($r['saldo_a_favor']).'.';
            $type = $r['success'] ? 'success' : 'danger';
        }
    }
}

$payments = $lease ? db_query($mysqli,
    "SELECT p.*,
            GREATEST(0, p.valor_total - p.monto_pagado) AS saldo_pendiente
     FROM payments p WHERE lease_id=? ORDER BY periodo DESC",'i',[$lease['id']]) : [];

foreach ($payments as &$p) {
    if (!in_array($p['estado'],['pagado','condonado','validando']) && $lease) {
        $m = dpr_calcular_mora($mysqli,$lease['id'],(float)$p['valor_arriendo'],$p['fecha_vencimiento']);
        $p['mora_calc']=$m['valor_mora']; $p['dias_mora']=$m['dias_mora'];
    } else { $p['mora_calc']=$p['valor_mora']; $p['dias_mora']=0; }
}
unset($p);

// Abonos del contrato para mostrar historial
$abonos = $lease ? db_query($mysqli,
    "SELECT pp.*, pay.periodo
     FROM payment_partials pp
     JOIN payments pay ON pay.id=pp.payment_id
     WHERE pp.lease_id=? ORDER BY pp.created_at DESC LIMIT 20",'i',[$lease['id']]) : [];

$page_title='Mis Pagos'; $active_menu='payments';
require_once __DIR__ . '/../includes/header.php';
if ($msg): ?><div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Mis pagos</div>
    <div class="dpr-page-sub"><?= $lease ? h($lease['inmueble'].' · '.$lease['unidad']) : 'Sin contrato activo' ?>
    <?php if ($lease && (float)$lease['saldo_favor'] > 0): ?>
      · <span class="dpr-pill dpr-pill--ok">Saldo a favor: <?= fmt_money($lease['saldo_favor']) ?></span>
    <?php endif; ?></div>
  </div>
</div>

<?php if (!$lease): ?>
<div class="dpr-alert dpr-alert--info">No tienes un contrato activo.</div>
<?php else: ?>

<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead><tr><th>Periodo</th><th>Arriendo</th><th>Mora</th><th>Total</th><th>Abonado</th><th>Pendiente</th><th>Vence</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($payments as $p):
          $pill=match($p['estado']){'pagado'=>'ok','validando'=>'warn','moroso'=>'danger','pendiente'=>'info','condonado'=>'neutral',default=>'neutral'};
          $saldo_pend=(float)$p['saldo_pendiente'];
          $abonado=(float)$p['monto_pagado'];
        ?>
        <tr>
          <td><strong><?= h($p['periodo']) ?></strong><?php if ($p['tiene_abonos']): ?><span class="dpr-pill dpr-pill--info" style="font-size:10px;margin-left:6px">Parcial</span><?php endif; ?></td>
          <td><?= fmt_money($p['valor_arriendo']) ?></td>
          <td><?= $p['mora_calc']>0 ? '<span style="color:#dc2626">'.fmt_money($p['mora_calc']).'</span>' : '—' ?></td>
          <td><strong><?= fmt_money((float)$p['valor_arriendo']+(float)$p['mora_calc']) ?></strong></td>
          <td><?= $abonado>0 ? '<span style="color:#059669">'.fmt_money($abonado).'</span>' : '—' ?></td>
          <td><?= $saldo_pend>0 ? '<span style="color:#d97706">'.fmt_money($saldo_pend).'</span>' : '<span style="color:#059669">✓</span>' ?></td>
          <td><?= fmt_date($p['fecha_vencimiento']) ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst($p['estado']) ?></span></td>
          <td class="dpr-flex">
            <?php if ($p['comprobante_url'] && in_array($p['estado'],['pagado','validando'])): ?>
            <a href="<?= h($p['comprobante_url']) ?>" target="_blank" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Descargar</a>
            <?php endif; ?>
            <?php if (in_array($p['estado'],['pendiente','moroso'])): ?>
            <button class="dpr-btn dpr-btn--sm dpr-btn--primary" onclick="openUpload(<?= $p['id'] ?>,'<?= h($p['periodo']) ?>','<?= fmt_money((float)$p['valor_arriendo']+(float)$p['mora_calc']) ?>')">Pago total</button>
            <button class="dpr-btn dpr-btn--sm dpr-btn--secondary" onclick="openAbono(<?= $p['id'] ?>,'<?= h($p['periodo']) ?>','<?= fmt_money($saldo_pend) ?>')">+ Abono</button>
            <?php endif; ?>
            <?php if ($p['estado']==='validando'): ?>
            <span class="dpr-pill dpr-pill--warn">En revisión…</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="9" style="text-align:center;padding:24px" class="dpr-text-muted">Sin historial de pagos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($abonos): ?>
<div class="dpr-card">
  <div class="dpr-card__title">Historial de abonos</div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead><tr><th>Fecha</th><th>Periodo</th><th>Monto</th><th>Método</th><th>Tipo</th><th>Comprobante</th></tr></thead>
      <tbody>
        <?php foreach ($abonos as $a): ?>
        <tr>
          <td><?= date('d M Y H:i', strtotime($a['created_at'])) ?></td>
          <td><?= h($a['periodo']) ?></td>
          <td><strong><?= fmt_money((float)$a['monto']) ?></strong></td>
          <td><?= ucfirst($a['metodo']) ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $a['tipo']==='abono'?'info':($a['tipo']==='saldo_favor_aplicado'?'ok':'neutral') ?>"><?= $a['tipo']==='saldo_favor_aplicado'?'Saldo favor':ucfirst($a['tipo']) ?></span></td>
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
    Este registro es inmutable y sirve como prueba de que enviaste el pago a tiempo. Si realizas un pago que supera el total adeudado,
    el excedente queda como <strong>saldo a favor</strong> que se aplica automáticamente al siguiente período.
  </div>
</div>

<!-- Modal: Pago total -->
<div class="dpr-modal-backdrop" id="uploadModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('uploadModal')">&times;</button>
    <div class="dpr-modal__title">Subir comprobante de pago total</div>
    <div id="uploadInfo" style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_comprobante">
      <input type="hidden" name="payment_id" id="uploadPayId">
      <div class="dpr-dropzone" style="margin-bottom:14px" onclick="document.getElementById('fi').click()" ondragover="this.classList.add('dragover');event.preventDefault()" ondragleave="this.classList.remove('dragover')" ondrop="handleDrop(event)">
        <div class="dpr-dropzone__text">Arrastra tu archivo o haz clic aquí</div>
        <div style="font-size:12px;color:var(--text-muted)">PNG, JPG, WEBP o PDF · Máx 5 MB · Imágenes se reducen automáticamente</div>
        <div id="fprev" style="margin-top:8px;font-size:13px;color:var(--blue)"></div>
      </div>
      <input type="file" id="fi" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf" style="display:none" onchange="showPrev(this)" required>
      <div class="dpr-form-group" style="margin-bottom:14px"><label class="dpr-label">Nota (referencia, banco, fecha del pago)</label><textarea name="nota_inquilino" class="dpr-textarea" style="height:55px" placeholder="Ej: Transferencia Bancolombia 13/04/2026 ref. 123456"></textarea></div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('uploadModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Enviar comprobante</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Abono parcial -->
<div class="dpr-modal-backdrop" id="abonoModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('abonoModal')">&times;</button>
    <div class="dpr-modal__title">Registrar abono / pago parcial</div>
    <div id="abonoInfo" style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="abono">
      <input type="hidden" name="payment_id" id="abonoPayId">
      <div class="dpr-form-grid">
        <div class="dpr-form-group"><label class="dpr-label">Monto del abono ($)</label><input type="number" name="monto_abono" class="dpr-input" min="1" step="1000" required placeholder="Ej: 500000"></div>
        <div class="dpr-form-group"><label class="dpr-label">Método</label><select name="metodo_abono" class="dpr-select"><?php foreach (['transferencia','nequi','daviplata','pse','efectivo','tarjeta','otro'] as $m): ?><option value="<?= $m ?>"><?= ucfirst($m) ?></option><?php endforeach; ?></select></div>
        <div class="dpr-form-group dpr-form-group--full"><label class="dpr-label">Referencia del pago</label><input type="text" name="ref_abono" class="dpr-input" placeholder="No. transacción o comprobante…"></div>
        <div class="dpr-form-group dpr-form-group--full"><label class="dpr-label">Adjuntar comprobante</label><input type="file" name="comp_abono" class="dpr-input" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
        <div class="dpr-form-group dpr-form-group--full"><label class="dpr-label">Nota adicional</label><textarea name="nota_abono" class="dpr-textarea" style="height:55px"></textarea></div>
      </div>
      <div class="dpr-alert dpr-alert--info" style="font-size:12px;margin-top:10px">Si abonás más de lo que debes, el excedente quedará como saldo a favor para tu próximo periodo.</div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('abonoModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Registrar abono</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function openUpload(id,periodo,monto){document.getElementById('uploadPayId').value=id;document.getElementById('uploadInfo').innerHTML=`Periodo: <strong>${periodo}</strong> · Total: <strong>${monto}</strong>`;document.getElementById('uploadModal').classList.add('open')}
function openAbono(id,periodo,pendiente){document.getElementById('abonoPayId').value=id;document.getElementById('abonoInfo').innerHTML=`Periodo: <strong>${periodo}</strong> · Saldo pendiente: <strong>${pendiente}</strong>`;document.getElementById('abonoModal').classList.add('open')}
function closeM(id){document.getElementById(id).classList.remove('open')}
function showPrev(inp){document.getElementById('fprev').textContent=inp.files[0]?.name?'Seleccionado: '+inp.files[0].name:''}
function handleDrop(e){e.preventDefault();e.currentTarget.classList.remove('dragover');if(e.dataTransfer.files.length){document.getElementById('fi').files=e.dataTransfer.files;showPrev(document.getElementById('fi'))}}
document.querySelectorAll('.dpr-modal-backdrop').forEach(el=>el.addEventListener('click',e=>{if(e.target===el)el.classList.remove('open')}));
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
