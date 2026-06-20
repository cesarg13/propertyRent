<?php
// =============================================================
//  PropertyRent — Inquilino: Soporte / Tickets con adjuntos
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user = dpr_current_user();
$msg  = $type = '';

$lease = db_query($mysqli,
    "SELECT l.id, l.unit_id FROM leases l WHERE l.tenant_id = ? AND l.estado = 'activo' LIMIT 1",
    'i', [$user['id']]);
$lease = $lease[0] ?? null;

// Verificar si la tabla support_files existe
$tbl_check = $mysqli->query("SHOW TABLES LIKE 'support_ticket_files'");
$has_files_table = $tbl_check && $tbl_check->num_rows > 0;

// Crear tabla si no existe
if (!$has_files_table) {
    $mysqli->query("CREATE TABLE IF NOT EXISTS `support_ticket_files` (
        `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `ticket_id`  int(10) UNSIGNED NOT NULL,
        `filename`   varchar(255) NOT NULL,
        `url`        varchar(255) NOT NULL,
        `mime`       varchar(80)  DEFAULT NULL,
        `subido_por` int(10) UNSIGNED NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `ticket_id` (`ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $has_files_table = true;
}

// ---------------------------------------------------------------
// POST: crear ticket con hasta 5 archivos adjuntos
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    if ($act === 'create_ticket') {
        $categoria   = $_POST['categoria']   ?? 'otro';
        $asunto      = trim($_POST['asunto'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $prioridad   = $_POST['prioridad']   ?? 'media';

        if (!$asunto || !$descripcion) {
            $msg = 'Asunto y descripción son obligatorios.'; $type = 'danger';
        } else {
            $r = db_execute($mysqli,
                "INSERT INTO support_tickets (tenant_id, unit_id, categoria, asunto, descripcion, prioridad)
                 VALUES (?, ?, ?, ?, ?, ?)",
                'iissss', [$user['id'], $lease['unit_id'] ?? null, $categoria, $asunto, $descripcion, $prioridad]);

            if ($r['success'] && $r['insert_id']) {
                $ticket_id = $r['insert_id'];
                $uploaded  = 0;
                $errors    = [];

                // Procesar hasta 5 archivos
                if (!empty($_FILES['adjuntos']['name'][0])) {
                    $total = count($_FILES['adjuntos']['name']);
                    $total = min($total, 5); // máximo 5 archivos
                    for ($i = 0; $i < $total; $i++) {
                        if ($_FILES['adjuntos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                        if (empty($_FILES['adjuntos']['name'][$i])) continue;

                        $file = [
                            'name'     => $_FILES['adjuntos']['name'][$i],
                            'type'     => $_FILES['adjuntos']['type'][$i],
                            'tmp_name' => $_FILES['adjuntos']['tmp_name'][$i],
                            'error'    => $_FILES['adjuntos']['error'][$i],
                            'size'     => $_FILES['adjuntos']['size'][$i],
                        ];

                        // Imágenes: comprimir a WebP; documentos: subida directa
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime  = $finfo->file($file['tmp_name']);
                        $img_types = ['image/jpeg','image/png','image/webp','image/gif'];

                        if (in_array($mime, $img_types)) {
                            $up = dpr_upload_image($file, 'soporte');
                        } else {
                            $up = dpr_upload_file($file, 'soporte');
                        }

                        if ($up['success']) {
                            db_execute($mysqli,
                                "INSERT INTO support_ticket_files (ticket_id, filename, url, mime, subido_por)
                                 VALUES (?, ?, ?, ?, ?)",
                                'isssi', [$ticket_id, $file['name'], $up['url'], $mime, $user['id']]);
                            $uploaded++;
                        } else {
                            $errors[] = $file['name'] . ': ' . $up['error'];
                        }
                    }
                }

                $msg = 'Solicitud enviada. El administrador te responderá pronto.';
                if ($uploaded > 0) $msg .= " ($uploaded archivo(s) adjunto(s)).";
                if ($errors)       $msg .= ' Errores: ' . implode(', ', $errors);
                $type = $errors ? 'warn' : 'success';
            } else {
                $msg = 'No se pudo crear el ticket. Intenta de nuevo.'; $type = 'danger';
            }
        }
    }
}

$tickets = db_query($mysqli,
    "SELECT * FROM support_tickets WHERE tenant_id = ? ORDER BY created_at DESC",
    'i', [$user['id']]);

// Adjuntos por ticket
$archivos_map = [];
if ($has_files_table && $tickets) {
    $ids = implode(',', array_column($tickets, 'id'));
    if ($ids) {
        $files_rows = db_query($mysqli,
            "SELECT * FROM support_ticket_files WHERE ticket_id IN ($ids) ORDER BY created_at ASC");
        foreach ($files_rows as $f) {
            $archivos_map[$f['ticket_id']][] = $f;
        }
    }
}

$page_title  = 'Soporte';
$active_menu = 'support';
require_once __DIR__ . '/../includes/header.php';

if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

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
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create_ticket">
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Categoría</label>
        <select name="categoria" class="dpr-select">
          <option value="mantenimiento">Mantenimiento / Reparación</option>
          <option value="consulta_pago">Consulta de pago</option>
          <option value="documentos">Documentos</option>
          <option value="seguridad">Seguridad</option>
          <option value="otro">Otro</option>
        </select>
      </div>
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Prioridad</label>
        <select name="prioridad" class="dpr-select">
          <option value="baja">Baja</option>
          <option value="media" selected>Media</option>
          <option value="alta">Alta</option>
          <option value="urgente">🚨 Urgente</option>
        </select>
      </div>
      <div class="dpr-form-group" style="margin-bottom:12px">
        <label class="dpr-label">Asunto *</label>
        <input type="text" name="asunto" class="dpr-input" required maxlength="200"
          placeholder="Ej: Humedad en pared del baño">
      </div>
      <div class="dpr-form-group" style="margin-bottom:14px">
        <label class="dpr-label">Descripción detallada *</label>
        <textarea name="descripcion" class="dpr-textarea" required style="height:90px"
          placeholder="Describe el problema con el mayor detalle posible: ubicación exacta, desde cuándo ocurre, qué tan grave es…"></textarea>
      </div>

      <!-- ADJUNTOS MÚLTIPLES -->
      <div class="dpr-form-group" style="margin-bottom:16px">
        <label class="dpr-label">Fotos o archivos adjuntos <span style="color:var(--text-muted);font-weight:400">(hasta 5)</span></label>
        <div class="dpr-dropzone" id="dropzone"
             onclick="document.getElementById('adjuntos').click()"
             ondragover="dzOver(event)"
             ondragleave="dzLeave(event)"
             ondrop="dzDrop(event)">
          <svg width="28" height="28" viewBox="0 0 28 28" fill="none" style="color:var(--blue);margin-bottom:6px">
            <path d="M14 6v12M8 12l6-6 6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M4 20h20" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
          </svg>
          <div class="dpr-dropzone__text">Arrastra fotos o documentos aquí, o haz clic</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:3px">JPG, PNG, WEBP, PDF · Máx 5 MB por archivo · Hasta 5 archivos</div>
          <div id="fileList" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px"></div>
        </div>
        <input type="file" id="adjuntos" name="adjuntos[]" multiple
               accept=".jpg,.jpeg,.png,.webp,.pdf"
               style="display:none" onchange="updateFileList(this)">
        <div style="font-size:11px;color:var(--text-muted);margin-top:5px">
          Las imágenes se comprimen automáticamente. Sube fotos del problema para que el administrador lo vea claramente.
        </div>
      </div>

      <button type="submit" class="dpr-btn dpr-btn--primary" style="width:100%;justify-content:center">
        <svg width="15" height="15" viewBox="0 0 15 15" fill="none" style="margin-right:6px"><path d="M13 2L2 7l4 2.5L8 13l2-4 3 1.5L13 2z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
        Enviar solicitud
      </button>
    </form>
  </div>

  <!-- Historial de tickets -->
  <div class="dpr-card">
    <div class="dpr-card__title">Mis solicitudes <span class="dpr-pill dpr-pill--info" style="margin-left:6px"><?= count($tickets) ?></span></div>
    <?php if (!$tickets): ?>
    <div class="dpr-text-muted" style="text-align:center;padding:32px 20px">
      <svg width="36" height="36" viewBox="0 0 36 36" fill="none" style="color:var(--border-md);margin-bottom:10px;display:block;margin-inline:auto"><path d="M6 30V8a2 2 0 0 1 2-2h20a2 2 0 0 1 2 2v22l-6-3-6 3-6-3-6 3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 13h12M12 18h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      Aún no has creado solicitudes.
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px;max-height:70vh;overflow-y:auto">
      <?php foreach ($tickets as $t):
          $spill = 'neutral';
          if ($t['estado'] === 'abierto')     $spill = 'info';
          elseif ($t['estado'] === 'en_proceso') $spill = 'warn';
          elseif ($t['estado'] === 'resuelto')   $spill = 'ok';

          $ppill = 'neutral';
          if ($t['prioridad'] === 'urgente') $ppill = 'danger';
          elseif ($t['prioridad'] === 'alta') $ppill = 'warn';
          elseif ($t['prioridad'] === 'media') $ppill = 'info';

          $adjuntos_ticket = $archivos_map[$t['id']] ?? [];
      ?>
      <div style="border:0.5px solid var(--border);border-radius:var(--radius);padding:14px;transition:box-shadow .15s"
           onmouseenter="this.style.boxShadow='0 2px 12px rgba(15,45,82,.08)'"
           onmouseleave="this.style.boxShadow=''">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
          <div style="font-size:13px;font-weight:600;color:var(--navy);flex:1;margin-right:8px"><?= h($t['asunto']) ?></div>
          <div class="dpr-flex" style="flex-shrink:0;gap:4px">
            <span class="dpr-pill dpr-pill--<?= $ppill ?>"><?= ucfirst($t['prioridad']) ?></span>
            <span class="dpr-pill dpr-pill--<?= $spill ?>"><?= ucfirst(str_replace('_', ' ', $t['estado'])) ?></span>
          </div>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px">
          <?= fmt_date($t['created_at']) ?> · <?= ucfirst($t['categoria']) ?>
        </div>
        <div style="font-size:13px;color:var(--text);line-height:1.5;margin-bottom:8px">
          <?= nl2br(h($t['descripcion'])) ?>
        </div>

        <?php if ($adjuntos_ticket): ?>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
          <?php foreach ($adjuntos_ticket as $af):
              $is_img = strpos($af['mime'] ?? '', 'image/') === 0;
          ?>
          <a href="<?= h($af['url']) ?>" target="_blank"
             style="display:flex;align-items:center;gap:5px;background:var(--gray-card);border:.5px solid var(--border);border-radius:6px;padding:5px 10px;font-size:11px;color:var(--text);text-decoration:none;transition:background .12s"
             onmouseenter="this.style.background='var(--blue-lt)'"
             onmouseleave="this.style.background='var(--gray-card)'">
            <?php if ($is_img): ?>
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><rect x="1" y="1" width="10" height="10" rx="1.5" stroke="currentColor" stroke-width="1.1"/><circle cx="4" cy="4" r="1" fill="currentColor"/><path d="M1 8l3-3 2 2 2-2 3 3" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 1h5l3 3v7H3V1z" stroke="currentColor" stroke-width="1.1" stroke-linejoin="round"/><path d="M7 1v3h3" stroke="currentColor" stroke-width="1.1"/></svg>
            <?php endif; ?>
            <?= h(mb_strimwidth($af['filename'], 0, 22, '…')) ?>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($t['respuesta']): ?>
        <div style="background:#f0f9ff;border-left:3px solid #0284c7;padding:10px 12px;border-radius:0 6px 6px 0;font-size:12px;margin-top:8px">
          <strong style="color:#0c4a6e;display:block;margin-bottom:3px">Respuesta del administrador:</strong>
          <?= nl2br(h($t['respuesta'])) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function dzOver(e) { e.preventDefault(); document.getElementById('dropzone').classList.add('dragover'); }
function dzLeave(e) { document.getElementById('dropzone').classList.remove('dragover'); }
function dzDrop(e) {
  e.preventDefault();
  document.getElementById('dropzone').classList.remove('dragover');
  const inp = document.getElementById('adjuntos');
  const dt  = e.dataTransfer;
  if (dt.files.length) {
    // Can't set files directly on input from drop in all browsers, use DataTransfer
    try {
      const transfer = new DataTransfer();
      const max = Math.min(dt.files.length, 5);
      for (let i = 0; i < max; i++) transfer.items.add(dt.files[i]);
      inp.files = transfer.files;
    } catch(err) {
      // Fallback: just trigger click
      inp.click();
    }
    updateFileList(inp);
  }
}
function updateFileList(inp) {
  const list = document.getElementById('fileList');
  list.innerHTML = '';
  const files = Array.from(inp.files).slice(0, 5);
  files.forEach(function(f) {
    const tag = document.createElement('span');
    tag.style.cssText = 'display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1d4ed8;border-radius:4px;padding:3px 8px;font-size:11px;font-weight:500';
    tag.textContent = f.name.length > 22 ? f.name.substring(0, 22) + '…' : f.name;
    list.appendChild(tag);
  });
  if (inp.files.length > 5) {
    const warn = document.createElement('span');
    warn.style.cssText = 'font-size:11px;color:#d97706';
    warn.textContent = 'Solo se subirán los primeros 5 archivos.';
    list.appendChild(warn);
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
