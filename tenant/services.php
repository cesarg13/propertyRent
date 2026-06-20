<?php
// =============================================================
//  PropertyRent — Inquilino: Servicios Públicos
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user  = dpr_current_user();
$msg   = $type = '';

$lease = db_query($mysqli,
    "SELECT l.id, un.nombre AS unidad, pr.nombre AS inmueble
     FROM leases l
     JOIN units un ON un.id = l.unit_id
     JOIN properties pr ON pr.id = un.property_id
     WHERE l.tenant_id = ? AND l.estado = 'activo' LIMIT 1",
    'i', [$user['id']]);
$lease = $lease[0] ?? null;

// ---------------------------------------------------------------
// Detectar si la migración de servicios está aplicada
// ---------------------------------------------------------------
$col = $mysqli->query("SHOW COLUMNS FROM public_services LIKE 'comprobante_url'");
$has_upload = $col && $col->num_rows > 0;

// ---------------------------------------------------------------
// POST: subir comprobante de servicio
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lease && $has_upload) {
    $act        = $_POST['action']     ?? '';
    $service_id = (int)($_POST['service_id'] ?? 0);

    if ($act === 'upload_comprobante' && $service_id) {
        $own = db_query($mysqli,
            "SELECT id, estado FROM public_services WHERE id = ? AND lease_id = ?",
            'ii', [$service_id, $lease['id']]);

        if (!$own) {
            $msg = 'Servicio no encontrado.'; $type = 'danger';
        } elseif ($own[0]['estado'] === 'pagado') {
            $msg = 'Este servicio ya está marcado como pagado.'; $type = 'warn';
        } elseif ($own[0]['estado'] === 'validando') {
            $msg = 'Ya enviaste un comprobante para este servicio. Espera la revisión del administrador.'; $type = 'warn';
        } elseif (empty($_FILES['comprobante']['name'])) {
            $msg = 'Debes seleccionar un archivo.'; $type = 'danger';
        } else {
            $up = dpr_upload_file($_FILES['comprobante'], 'comprobantes');
            if (!$up['success']) {
                $msg = $up['error']; $type = 'danger';
            } else {
                db_execute($mysqli,
                    "UPDATE public_services
                     SET estado='validando', comprobante_url=?, nota=?, updated_at=NOW()
                     WHERE id=?",
                    'ssi', [$up['url'], trim($_POST['nota'] ?? ''), $service_id]);
                dpr_audit_log($mysqli, $user['id'], 'UPLOAD', 'public_services',
                    $service_id, "Subió comprobante: {$up['filename']}");
                $msg = 'Comprobante enviado. El administrador lo revisará pronto.'; $type = 'success';
            }
        }
    }
}

$f_periodo = $_GET['periodo'] ?? date('Y-m');

$servicios = $lease ? db_query($mysqli,
    "SELECT ps.*,
            mr.lectura_anterior, mr.lectura_actual, mr.consumo, mr.tarifa
     FROM public_services ps
     LEFT JOIN meter_readings mr
            ON mr.unit_id = ps.unit_id
           AND mr.tipo    = ps.tipo
           AND mr.periodo = ps.periodo
     WHERE ps.lease_id = ? AND ps.periodo = ?
     ORDER BY ps.tipo",
    'is', [$lease['id'] ?? 0, $f_periodo]) : [];

$historial = $lease ? db_query($mysqli,
    "SELECT periodo,
            SUM(valor) AS total,
            SUM(CASE WHEN estado='pagado' THEN valor ELSE 0 END) AS pagado,
            SUM(CASE WHEN estado IN ('pendiente','validando') THEN valor ELSE 0 END) AS pendiente
     FROM public_services WHERE lease_id = ?
     GROUP BY periodo ORDER BY periodo DESC LIMIT 6",
    'i', [$lease['id'] ?? 0]) : [];

$page_title  = 'Servicios Públicos';
$active_menu = 'services';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<?php if (!$has_upload): ?>
<div class="dpr-alert dpr-alert--warn">
  Para habilitar el envío de comprobantes de servicios ejecuta
  <code>migration_services_upload.sql</code> en tu base de datos.
</div>
<?php endif; ?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Servicios públicos</div>
    <div class="dpr-page-sub"><?= $lease ? h($lease['inmueble'] . ' · ' . $lease['unidad']) : '' ?></div>
  </div>
  <form method="GET" style="display:flex;align-items:center;gap:8px">
    <label class="dpr-label" style="margin:0">Periodo:</label>
    <input type="month" name="periodo" class="dpr-input"
           value="<?= h($f_periodo) ?>" style="width:160px" onchange="this.form.submit()">
  </form>
</div>

<?php if (!$lease): ?>
<div class="dpr-alert dpr-alert--info">No tienes contrato activo. Contacta al administrador.</div>
<?php else: ?>

<?php if ($servicios): ?>
<div class="dpr-card">
  <div class="dpr-card__title">Detalle · <?= h($f_periodo) ?></div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr>
          <th>Servicio</th><th>Lect. ant.</th><th>Lect. act.</th>
          <th>Consumo</th><th>Tarifa</th><th>Valor</th><th>Estado</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($servicios as $s):
            $pill = 'neutral';
            if     ($s['estado'] === 'pagado')    $pill = 'ok';
            elseif ($s['estado'] === 'validando') $pill = 'warn';
            elseif ($s['estado'] === 'pendiente') $pill = 'warn';
            elseif ($s['estado'] === 'incluido')  $pill = 'info';
            $puede_pagar = $s['estado'] === 'pendiente' && !$s['incluido'] && $has_upload;
        ?>
        <tr>
          <td><strong><?= ucfirst(h($s['tipo'])) ?></strong></td>
          <td><?= $s['lectura_anterior'] !== null ? number_format((float)$s['lectura_anterior'], 2) : '—' ?></td>
          <td><?= $s['lectura_actual']   !== null ? number_format((float)$s['lectura_actual'],   2) : '—' ?></td>
          <td><?= $s['consumo']          !== null ? number_format((float)$s['consumo'],          2) : '—' ?></td>
          <td><?= $s['tarifa']           !== null ? fmt_money((float)$s['tarifa']) . '<span style="font-size:11px">/ud</span>' : '—' ?></td>
          <td><?= $s['incluido'] ? '<span class="dpr-text-muted">Incluido</span>' : fmt_money((float)$s['valor']) ?></td>
          <td><span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst(h($s['estado'])) ?></span></td>
          <td class="dpr-flex">
            <?php
            $comp = $has_upload ? ($s['comprobante_url'] ?? null) : null;
            if ($comp): ?>
              <a href="<?= h($comp) ?>" target="_blank"
                 class="dpr-btn dpr-btn--sm dpr-btn--secondary">Ver</a>
            <?php endif; ?>
            <?php if ($puede_pagar): ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--primary"
                onclick="openUpload(<?= (int)$s['id'] ?>,'<?= ucfirst(h($s['tipo'])) ?>','<?= fmt_money((float)$s['valor']) ?>')">
                Subir comprobante
              </button>
            <?php elseif ($s['estado'] === 'validando'): ?>
              <span class="dpr-pill dpr-pill--warn" style="font-size:10px">En revisión…</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="dpr-alert dpr-alert--info">No hay registros de servicios para <?= h($f_periodo) ?>.</div>
<?php endif; ?>

<?php if ($historial): ?>
<div class="dpr-card">
  <div class="dpr-card__title">Resumen histórico</div>
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr><th>Periodo</th><th>Total</th><th>Pagado</th><th>Pendiente</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($historial as $hr): ?>
        <tr>
          <td><?= h($hr['periodo']) ?></td>
          <td><?= fmt_money((float)$hr['total']) ?></td>
          <td style="color:#065f46"><?= fmt_money((float)$hr['pagado']) ?></td>
          <td><?= (float)$hr['pendiente'] > 0
                ? '<span style="color:#d97706">' . fmt_money((float)$hr['pendiente']) . '</span>'
                : '<span style="color:#059669">✓</span>' ?></td>
          <td><a href="?periodo=<?= h($hr['periodo']) ?>"
                 class="dpr-btn dpr-btn--sm dpr-btn--secondary">Ver detalle</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($has_upload): ?>
<!-- Modal: subir comprobante -->
<div class="dpr-modal-backdrop" id="uploadModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeM()">&times;</button>
    <div class="dpr-modal__title">Subir comprobante de servicio</div>
    <div id="uploadInfo"
         style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px">
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="upload_comprobante">
      <input type="hidden" name="service_id" id="uploadServiceId">
      <div class="dpr-dropzone"
           onclick="document.getElementById('fi1').click()"
           ondragover="this.classList.add('dragover');event.preventDefault()"
           ondragleave="this.classList.remove('dragover')"
           ondrop="handleDrop(event)">
        <div class="dpr-dropzone__text">Arrastra tu archivo o haz clic aquí</div>
        <div style="font-size:12px;color:var(--text-muted)">PNG, JPG, WEBP o PDF · Máx 5 MB</div>
        <div id="fp1" style="margin-top:6px;font-size:13px;color:var(--blue)"></div>
      </div>
      <input type="file" id="fi1" name="comprobante"
             accept=".jpg,.jpeg,.png,.webp,.pdf"
             style="display:none"
             onchange="showPrev(this)" required>
      <div class="dpr-form-group" style="margin-top:12px;margin-bottom:0">
        <label class="dpr-label">Nota (banco, referencia)</label>
        <textarea name="nota" class="dpr-textarea" style="height:55px"
          placeholder="Ej: Pagado en app EPM ref. 123456"></textarea>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeM()">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Enviar comprobante</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php endif; // end if $lease ?>

<script>
function openUpload(id, tipo, valor) {
  document.getElementById('uploadServiceId').value = id;
  document.getElementById('uploadInfo').innerHTML =
    'Servicio: <strong>' + tipo + '</strong> &nbsp;·&nbsp; Valor: <strong>' + valor + '</strong>';
  document.getElementById('uploadModal').classList.add('open');
}
function closeM() {
  document.getElementById('uploadModal').classList.remove('open');
}
function showPrev(inp) {
  document.getElementById('fp1').textContent =
    inp.files[0] ? 'Seleccionado: ' + inp.files[0].name : '';
}
function handleDrop(e) {
  e.preventDefault();
  e.currentTarget.classList.remove('dragover');
  if (e.dataTransfer.files.length) {
    document.getElementById('fi1').files = e.dataTransfer.files;
    showPrev(document.getElementById('fi1'));
  }
}
document.getElementById('uploadModal').addEventListener('click', function(e) {
  if (e.target === this) closeM();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
