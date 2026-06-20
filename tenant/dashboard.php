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
$msg = $type = '';

// Migración parciales
$_col        = $mysqli->query("SHOW COLUMNS FROM payments LIKE 'monto_pagado'");
$has_parcial = $_col && $_col->num_rows > 0;

// Contrato activo
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
$mora_info   = ['dias_mora' => 0, 'valor_mora' => 0, 'tasa' => 0];
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

// Días para vencimiento
$dias_restantes = null;
$vence_pronto   = false;
if ($pago_actual) {
    $hoy  = new DateTime();
    $venc = new DateTime($pago_actual['fecha_vencimiento']);
    $diff = (int)$hoy->diff($venc)->days;
    $dias_restantes = $hoy <= $venc ? $diff : -$diff;
    $vence_pronto = $dias_restantes >= 0 && $dias_restantes <= 5;
}

// Saldo a favor
$saldo_favor = 0.0;
if ($has_parcial && $lease) {
    $sf = db_query($mysqli, "SELECT saldo_favor FROM leases WHERE id=?", 'i', [$lease['id']]);
    $saldo_favor = (float)($sf[0]['saldo_favor'] ?? 0);
}

// Abonado en período actual
$abonado_actual = $has_parcial && $pago_actual ? (float)($pago_actual['monto_pagado'] ?? 0) : 0;

// ---------------------------------------------------------------
// POST: subir comprobante desde el dashboard
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lease) {
    $act        = $_POST['action']     ?? '';
    $payment_id = (int)($_POST['payment_id'] ?? 0);

    if ($act === 'upload_comprobante' && $payment_id) {
        $own = db_query($mysqli,
            "SELECT id, estado FROM payments WHERE id=? AND lease_id=?",
            'ii', [$payment_id, $lease['id']]);

        if (!$own || in_array($own[0]['estado'], ['pagado','condonado'])) {
            $msg = 'No puedes subir comprobante para este período.'; $type = 'danger';
        } elseif ($own[0]['estado'] === 'validando') {
            $msg = 'Ya tienes un comprobante en revisión para este período.'; $type = 'warn';
        } elseif (empty($_FILES['comprobante']['name'])) {
            $msg = 'Selecciona un archivo.'; $type = 'danger';
        } else {
            $up = dpr_upload_file($_FILES['comprobante'], 'comprobantes');
            if (!$up['success']) {
                $msg = $up['error']; $type = 'danger';
            } else {
                db_execute($mysqli,
                    "UPDATE payments SET estado='validando', comprobante_url=?, nota_inquilino=?, updated_at=NOW() WHERE id=?",
                    'ssi', [$up['url'], trim($_POST['nota_inquilino'] ?? ''), $payment_id]);
                dpr_audit_log($mysqli, $user['id'], 'UPLOAD', 'payments', $payment_id, "Subió comprobante");
                $msg = 'Comprobante enviado. El administrador lo revisará pronto.'; $type = 'success';
                // Refrescar pago_actual
                $rows = db_query($mysqli, "SELECT * FROM payments WHERE id=? LIMIT 1", 'i', [$payment_id]);
                $pago_actual = $rows[0] ?? $pago_actual;
            }
        }
    }

    if ($act === 'abono' && $payment_id && $has_parcial) {
        $monto  = (float)($_POST['monto_abono'] ?? 0);
        $metodo = $_POST['metodo_abono'] ?? 'transferencia';
        $ref    = trim($_POST['ref_abono']  ?? '');
        $nota   = trim($_POST['nota_abono'] ?? '');
        $comp_url = null;
        if (!empty($_FILES['comp_abono']['name'])) {
            $up = dpr_upload_file($_FILES['comp_abono'], 'comprobantes');
            if ($up['success']) $comp_url = $up['url'];
        }
        if ($monto <= 0) {
            $msg = 'El monto debe ser mayor a cero.'; $type = 'danger';
        } else {
            $r = dpr_registrar_abono($mysqli, $payment_id, $lease['id'], $monto,
                                     $metodo, $ref, $comp_url, $nota, $user['id'], 'tenant');
            $msg  = $r['success'] ? 'Abono enviado para revisión.' : $r['error'];
            if ($r['success'] && !empty($r['saldo_a_favor']) && $r['saldo_a_favor'] > 0)
                $msg .= ' Saldo a favor: ' . fmt_money($r['saldo_a_favor']) . '.';
            $type = $r['success'] ? 'success' : 'danger';
        }
    }
}

// Historial últimos 6
$historial = $lease ? db_query($mysqli,
    "SELECT * FROM payments WHERE lease_id=? ORDER BY periodo DESC LIMIT 6",
    'i', [$lease['id']]) : [];

// Servicios pendientes
$servicios_pendientes = $lease ? db_query($mysqli,
    "SELECT * FROM public_services WHERE lease_id=? AND estado='pendiente' AND periodo=?",
    'is', [$lease['id'], $periodo]) : [];

$page_title  = 'Mi cuenta';
$active_menu = 'dashboard';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Bienvenido, <?= h(explode(' ', $user['nombre'])[0]) ?></div>
    <div class="dpr-page-sub">
      <?= $lease ? h($lease['inmueble'] . ' · ' . $lease['unidad'] . ' · ' . $lease['ciudad']) : 'Sin contrato activo' ?>
      <?php if ($saldo_favor > 0): ?>
        &nbsp;· <span class="dpr-pill dpr-pill--ok">Saldo a favor: <?= fmt_money($saldo_favor) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($pago_actual): ?>
    <?php if ($mora_info['valor_mora'] > 0): ?>
      <span class="dpr-pill dpr-pill--danger" style="font-size:13px;padding:6px 14px">En mora</span>
    <?php elseif ($vence_pronto): ?>
      <span class="dpr-pill dpr-pill--warn" style="font-size:13px;padding:6px 14px">Vence en <?= $dias_restantes ?> día<?= $dias_restantes != 1 ? 's' : '' ?></span>
    <?php elseif ($pago_actual['estado'] === 'pagado'): ?>
      <span class="dpr-pill dpr-pill--ok" style="font-size:13px;padding:6px 14px">Al día ✓</span>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (!$lease): ?>
<div class="dpr-alert dpr-alert--info">No tienes un contrato activo. Contacta al administrador.</div>
<?php else: ?>

<?php if ($mora_info['valor_mora'] > 0): ?>
<div class="dpr-alert dpr-alert--danger">
  <svg width="18" height="18" viewBox="0 0 18 18" fill="none" style="flex-shrink:0"><circle cx="9" cy="9" r="8" stroke="currentColor" stroke-width="1.4"/><path d="M9 5v4M9 11.5v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
  Tienes <strong><?= $mora_info['dias_mora'] ?> días de mora</strong> en el período <?= h($periodo) ?>.
  Interés acumulado: <strong><?= fmt_money($mora_info['valor_mora']) ?></strong> (tasa <?= $mora_info['tasa'] ?>% mensual).
  Total a pagar: <strong><?= fmt_money((float)$lease['valor_arriendo'] + $mora_info['valor_mora']) ?></strong>.
</div>
<?php elseif ($vence_pronto && $pago_actual && $pago_actual['estado'] !== 'pagado'): ?>
<div class="dpr-alert dpr-alert--warn">
  <svg width="18" height="18" viewBox="0 0 18 18" fill="none" style="flex-shrink:0"><circle cx="9" cy="9" r="8" stroke="currentColor" stroke-width="1.4"/><path d="M9 5v4M9 11.5v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
  Tu pago del periodo <strong><?= h($periodo) ?></strong> vence el <strong><?= fmt_date($pago_actual['fecha_vencimiento']) ?></strong>.
  Tienes <strong><?= $dias_restantes ?> día<?= $dias_restantes != 1 ? 's' : '' ?></strong> para pagar sin mora.
</div>
<?php endif; ?>

<div class="dpr-grid-2" style="margin-bottom:18px">
  <!-- TARJETA ESTADO DE CUENTA -->
  <div class="dpr-card">
    <div class="dpr-card__title">Estado de cuenta · <?= date('F Y') ?></div>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:18px">
      <div style="display:flex;justify-content:space-between;font-size:14px">
        <span class="dpr-text-muted">Arriendo mensual</span>
        <strong><?= fmt_money((float)$lease['valor_arriendo']) ?></strong>
      </div>
      <?php if ($mora_info['valor_mora'] > 0): ?>
      <div style="display:flex;justify-content:space-between;font-size:14px">
        <span style="color:#991b1b">Mora (<?= $mora_info['dias_mora'] ?> días)</span>
        <strong style="color:#dc2626"><?= fmt_money($mora_info['valor_mora']) ?></strong>
      </div>
      <?php endif; ?>
      <?php if ($abonado_actual > 0): ?>
      <div style="display:flex;justify-content:space-between;font-size:14px">
        <span style="color:#065f46">Ya abonaste</span>
        <strong style="color:#059669">- <?= fmt_money($abonado_actual) ?></strong>
      </div>
      <?php endif; ?>
      <hr style="border:none;border-top:1px solid var(--border);margin:2px 0">
      <div style="display:flex;justify-content:space-between;font-size:16px">
        <span style="font-weight:600">Total a pagar</span>
        <strong style="color:var(--navy);font-size:18px"><?= fmt_money(max(0, (float)$lease['valor_arriendo'] + $mora_info['valor_mora'] - $abonado_actual)) ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px">
        <span class="dpr-text-muted">Fecha límite</span>
        <span><?= $pago_actual ? fmt_date($pago_actual['fecha_vencimiento']) : '—' ?></span>
      </div>
    </div>

    <?php if ($pago_actual && in_array($pago_actual['estado'], ['pendiente','moroso'])): ?>
      <div style="display:flex;gap:8px">
        <button class="dpr-btn dpr-btn--primary" style="flex:1;justify-content:center"
          onclick="openUpload(<?= (int)$pago_actual['id'] ?>,'<?= h($periodo) ?>','<?= fmt_money((float)$lease['valor_arriendo'] + $mora_info['valor_mora']) ?>')">
          <svg width="15" height="15" viewBox="0 0 15 15" fill="none" style="margin-right:5px"><path d="M7.5 2v8M4 6l3.5-4L11 6M2 13h11" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Subir comprobante
        </button>
        <?php if ($has_parcial): ?>
        <button class="dpr-btn dpr-btn--secondary"
          onclick="openAbono(<?= (int)$pago_actual['id'] ?>,'<?= h($periodo) ?>','<?= fmt_money(max(0,(float)$lease['valor_arriendo']+$mora_info['valor_mora']-$abonado_actual)) ?>')">
          + Abono
        </button>
        <?php endif; ?>
      </div>
    <?php elseif ($pago_actual && $pago_actual['estado'] === 'validando'): ?>
      <div class="dpr-alert dpr-alert--warn" style="margin-bottom:0">
        <svg width="15" height="15" viewBox="0 0 15 15" fill="none" style="flex-shrink:0"><circle cx="7.5" cy="7.5" r="6.5" stroke="currentColor" stroke-width="1.2"/><path d="M7.5 4v4M7.5 10v.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
        Comprobante enviado — en revisión por el administrador.
        <?php if ($has_parcial && isset($pago_actual['monto_pagado']) && (float)$pago_actual['monto_pagado'] > 0): ?>
          <br><span style="font-size:12px">Abonado: <?= fmt_money((float)$pago_actual['monto_pagado']) ?></span>
        <?php endif; ?>
      </div>
    <?php elseif ($pago_actual && $pago_actual['estado'] === 'pagado'): ?>
      <div class="dpr-alert dpr-alert--success" style="margin-bottom:0">
        ✓ Pago confirmado el <?= fmt_date($pago_actual['fecha_pago']) ?>.
        <?php if ($pago_actual['comprobante_url']): ?>
        <a href="<?= h($pago_actual['comprobante_url']) ?>" target="_blank" style="color:var(--success-tx);font-weight:500;margin-left:6px">Ver comprobante</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- DATOS DEL CONTRATO -->
  <div class="dpr-card">
    <div class="dpr-card__title">Datos de mi contrato</div>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
      <div style="display:flex;justify-content:space-between"><span class="dpr-text-muted">Inmueble</span><span><?= h($lease['inmueble']) ?></span></div>
      <div style="display:flex;justify-content:space-between"><span class="dpr-text-muted">Unidad</span><span><?= h($lease['unidad']) ?></span></div>
      <div style="display:flex;justify-content:space-between"><span class="dpr-text-muted">Inicio</span><span><?= fmt_date($lease['fecha_inicio']) ?></span></div>
      <div style="display:flex;justify-content:space-between"><span class="dpr-text-muted">Fin</span><span><?= $lease['fecha_fin'] ? fmt_date($lease['fecha_fin']) : 'Indefinido' ?></span></div>
      <div style="display:flex;justify-content:space-between"><span class="dpr-text-muted">Día límite de pago</span><span>Día <strong><?= (int)$lease['dia_pago'] ?></strong></span></div>
      <?php if ($lease['deposito']): ?>
      <div style="display:flex;justify-content:space-between"><span class="dpr-text-muted">Depósito</span><span><?= fmt_money((float)$lease['deposito']) ?></span></div>
      <?php endif; ?>
      <?php if ($saldo_favor > 0): ?>
      <div style="display:flex;justify-content:space-between;background:var(--success-bg);padding:6px 10px;border-radius:6px">
        <span style="color:var(--success-tx);font-weight:600">Saldo a favor</span>
        <span style="color:var(--success-tx);font-weight:700"><?= fmt_money($saldo_favor) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <div style="margin-top:14px;display:flex;gap:8px">
      <a href="payments.php" class="dpr-btn dpr-btn--secondary dpr-btn--sm">Ver todos los pagos</a>
      <a href="services.php" class="dpr-btn dpr-btn--secondary dpr-btn--sm">Servicios públicos</a>
    </div>
  </div>
</div>

<?php if ($servicios_pendientes): ?>
<div class="dpr-card">
  <div class="dpr-card__title">Servicios públicos pendientes · <?= h($periodo) ?></div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead><tr><th>Servicio</th><th>Valor</th><th>Acción</th></tr></thead>
      <tbody>
        <?php foreach ($servicios_pendientes as $s): ?>
        <tr>
          <td><?= ucfirst($s['tipo']) ?></td>
          <td><?= fmt_money((float)$s['valor']) ?></td>
          <td>
            <button class="dpr-btn dpr-btn--sm dpr-btn--primary"
              onclick="openUploadServicio(<?= (int)$s['id'] ?>,'<?= ucfirst($s['tipo']) ?>','<?= fmt_money((float)$s['valor']) ?>')">
              Subir comprobante
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- HISTORIAL COMPACTO -->
<div class="dpr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <div class="dpr-card__title" style="margin-bottom:0">Últimos pagos</div>
    <a href="payments.php" class="dpr-btn dpr-btn--secondary dpr-btn--sm">Ver todos</a>
  </div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead><tr><th>Periodo</th><th>Total</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($historial as $hp):
            $pill = 'neutral';
            if ($hp['estado'] === 'pagado')    $pill = 'ok';
            elseif ($hp['estado'] === 'validando') $pill = 'warn';
            elseif ($hp['estado'] === 'moroso')   $pill = 'danger';
            elseif ($hp['estado'] === 'pendiente') $pill = 'info';
            $puede = in_array($hp['estado'], ['pendiente','moroso']);
        ?>
        <tr>
          <td><strong><?= h($hp['periodo']) ?></strong></td>
          <td><?= fmt_money((float)$hp['valor_total']) ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst($hp['estado']) ?></span></td>
          <td class="dpr-flex">
            <?php if ($hp['comprobante_url']): ?>
              <a href="<?= h($hp['comprobante_url']) ?>" target="_blank" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Ver</a>
            <?php endif; ?>
            <?php if ($puede): ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--primary"
                onclick="openUpload(<?= (int)$hp['id'] ?>,'<?= h($hp['periodo']) ?>','<?= fmt_money((float)$hp['valor_total']) ?>')">
                Subir
              </button>
              <?php if ($has_parcial): ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--secondary"
                onclick="openAbono(<?= (int)$hp['id'] ?>,'<?= h($hp['periodo']) ?>','<?= fmt_money((float)$hp['valor_total']) ?>')">
                Abonar
              </button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$historial): ?>
        <tr><td colspan="4" class="dpr-text-muted" style="text-align:center;padding:20px">Sin historial.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Subir comprobante de arriendo -->
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
        <div class="dpr-dropzone__text">Arrastra tu archivo o haz clic</div>
        <div style="font-size:12px;color:var(--text-muted)">PNG, JPG, WEBP o PDF · Máx 5 MB</div>
        <div id="fp1" style="margin-top:6px;font-size:13px;color:var(--blue)"></div>
      </div>
      <input type="file" id="fi1" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf" style="display:none" onchange="showPrev(this,'fp1')" required>
      <div class="dpr-form-group" style="margin-top:12px;margin-bottom:0">
        <label class="dpr-label">Nota (banco, referencia, fecha del pago)</label>
        <textarea name="nota_inquilino" class="dpr-textarea" style="height:55px" placeholder="Ej: Transferencia Bancolombia 13/04 ref. 987654"></textarea>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('uploadModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Enviar comprobante</button>
      </div>
    </form>
  </div>
</div>

<?php if ($has_parcial): ?>
<!-- Modal: Abono desde dashboard -->
<div class="dpr-modal-backdrop" id="abonoModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('abonoModal')">&times;</button>
    <div class="dpr-modal__title">Registrar abono parcial</div>
    <div id="abonoInfo" style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="abono">
      <input type="hidden" name="payment_id" id="abonoPayId">
      <div class="dpr-form-grid">
        <div class="dpr-form-group">
          <label class="dpr-label">Monto ($)</label>
          <input type="number" name="monto_abono" class="dpr-input" min="100" step="1" required placeholder="Ej: 500000">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Método</label>
          <select name="metodo_abono" class="dpr-select">
            <?php foreach (['transferencia','nequi','daviplata','pse','efectivo','tarjeta','otro'] as $m): ?>
            <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Referencia</label>
          <input type="text" name="ref_abono" class="dpr-input" placeholder="No. transacción o comprobante">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Comprobante (opcional)</label>
          <div class="dpr-dropzone" onclick="document.getElementById('fi2').click()"
               ondragover="this.classList.add('dragover');event.preventDefault()"
               ondragleave="this.classList.remove('dragover')"
               ondrop="handleDrop(event,'fi2','fp2')" style="padding:12px">
            <div style="font-size:13px;color:var(--text-muted)">PNG, JPG, WEBP o PDF</div>
            <div id="fp2" style="margin-top:4px;font-size:13px;color:var(--blue)"></div>
          </div>
          <input type="file" id="fi2" name="comp_abono" accept=".jpg,.jpeg,.png,.webp,.pdf" style="display:none" onchange="showPrev(this,'fp2')">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Nota (opcional)</label>
          <textarea name="nota_abono" class="dpr-textarea" style="height:50px"></textarea>
        </div>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('abonoModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Enviar abono</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Modal: Comprobante de servicio -->
<div class="dpr-modal-backdrop" id="servicioModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM('servicioModal')">&times;</button>
    <div class="dpr-modal__title">Subir comprobante de servicio</div>
    <div id="servicioInfo" style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" action="services.php" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="upload_comprobante">
      <input type="hidden" name="service_id" id="servicioId">
      <div class="dpr-dropzone" onclick="document.getElementById('fi3').click()"
           ondragover="this.classList.add('dragover');event.preventDefault()"
           ondragleave="this.classList.remove('dragover')"
           ondrop="handleDrop(event,'fi3','fp3')">
        <div class="dpr-dropzone__text">Arrastra tu archivo o haz clic</div>
        <div style="font-size:12px;color:var(--text-muted)">PNG, JPG, WEBP o PDF · Máx 5 MB</div>
        <div id="fp3" style="margin-top:6px;font-size:13px;color:var(--blue)"></div>
      </div>
      <input type="file" id="fi3" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf" style="display:none" onchange="showPrev(this,'fp3')" required>
      <div class="dpr-form-group" style="margin-top:12px;margin-bottom:0">
        <label class="dpr-label">Nota (banco, referencia)</label>
        <textarea name="nota" class="dpr-textarea" style="height:50px"></textarea>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM('servicioModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Enviar comprobante</button>
      </div>
    </form>
  </div>
</div>

<?php endif; // end if $lease ?>

<script>
function openUpload(id, periodo, monto) {
  document.getElementById('uploadPayId').value = id;
  document.getElementById('uploadInfo').innerHTML = 'Periodo: <strong>' + periodo + '</strong> &nbsp;·&nbsp; Total: <strong>' + monto + '</strong>';
  closeM('abonoModal');
  document.getElementById('uploadModal').classList.add('open');
}
function openAbono(id, periodo, pendiente) {
  document.getElementById('abonoPayId').value = id;
  document.getElementById('abonoInfo').innerHTML = 'Periodo: <strong>' + periodo + '</strong> &nbsp;·&nbsp; Pendiente: <strong>' + pendiente + '</strong>';
  closeM('uploadModal');
  document.getElementById('abonoModal').classList.add('open');
}
function openUploadServicio(id, tipo, valor) {
  document.getElementById('servicioId').value = id;
  document.getElementById('servicioInfo').innerHTML = 'Servicio: <strong>' + tipo + '</strong> &nbsp;·&nbsp; Valor: <strong>' + valor + '</strong>';
  document.getElementById('servicioModal').classList.add('open');
}
function closeM(id) { var el = document.getElementById(id); if (el) el.classList.remove('open'); }
function showPrev(inp, pid) { document.getElementById(pid).textContent = inp.files[0] ? 'Seleccionado: ' + inp.files[0].name : ''; }
function handleDrop(e, iid, pid) {
  e.preventDefault(); e.currentTarget.classList.remove('dragover');
  if (e.dataTransfer.files.length) { document.getElementById(iid).files = e.dataTransfer.files; showPrev(document.getElementById(iid), pid); }
}
document.querySelectorAll('.dpr-modal-backdrop').forEach(function(el) {
  el.addEventListener('click', function(e) { if (e.target === el) el.classList.remove('open'); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
