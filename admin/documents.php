<?php
// =============================================================
//  PropertyRent — Admin: Gestión de Documentos
//  - Subir contratos, inventarios, paz y salvos, reglamentos
//  - Vincular a inquilino, unidad o contrato específico
//  - Descarga individual y filtros completos
//  - Eliminación con confirmación
//  - Upload 100% AJAX (sin recarga de página)
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

// ---------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------
$f_tipo     = $_GET['tipo']      ?? '';
$f_tenant   = (int)($_GET['tenant_id'] ?? 0);
$f_prop     = (int)($_GET['property_id'] ?? 0);

$where_parts = ['1=1'];
if ($f_tipo)   $where_parts[] = "d.tipo = '" . $mysqli->real_escape_string($f_tipo) . "'";
if ($f_tenant) $where_parts[] = "d.tenant_id = $f_tenant";
if ($f_prop)   $where_parts[] = "pr.id = $f_prop";
$where = implode(' AND ', $where_parts);

$docs = db_query($mysqli,
    "SELECT d.*,
            CONCAT(u.nombre,' ',u.apellido) AS inquilino,
            u.email AS tenant_email,
            un.nombre AS unidad,
            pr.nombre AS inmueble,
            CONCAT(adm.nombre,' ',adm.apellido) AS subido_por_nombre,
            l.id AS lease_id_val
     FROM documents d
     LEFT JOIN users u       ON u.id = d.tenant_id
     LEFT JOIN leases l      ON l.id = d.lease_id
     LEFT JOIN units un      ON un.id = COALESCE(d.unit_id, l.unit_id)
     LEFT JOIN properties pr ON pr.id = un.property_id
     LEFT JOIN users adm     ON adm.id = d.subido_por
     WHERE $where
     ORDER BY d.created_at DESC");

// Datos para selects del formulario
$all_tenants = db_query($mysqli,
    "SELECT u.id, CONCAT(u.nombre,' ',u.apellido) AS nombre, u.email
     FROM users u WHERE u.rol='tenant' AND u.estado='activo' ORDER BY u.apellido");

$all_leases = db_query($mysqli,
    "SELECT l.id, CONCAT(pr.nombre,' / ',un.nombre,' — ',u.nombre,' ',u.apellido) AS label
     FROM leases l
     JOIN units un ON un.id=l.unit_id
     JOIN properties pr ON pr.id=un.property_id
     JOIN users u ON u.id=l.tenant_id
     WHERE l.estado='activo'
     ORDER BY pr.nombre, un.nombre");

$all_units = db_query($mysqli,
    "SELECT u.id, CONCAT(p.nombre,' / ',u.nombre) AS label
     FROM units u JOIN properties p ON p.id=u.property_id
     WHERE u.estado != 'inactiva' ORDER BY p.nombre, u.nombre");

$all_props = db_query($mysqli,
    "SELECT id, nombre FROM properties WHERE estado='activo' ORDER BY nombre");

// Contadores por tipo
$contadores = db_query($mysqli,
    "SELECT tipo, COUNT(*) AS n FROM documents GROUP BY tipo ORDER BY tipo");
$cnt = [];
foreach ($contadores as $c) $cnt[$c['tipo']] = $c['n'];

$tipos_doc = [
    'contrato'    => 'Contratos',
    'inventario'  => 'Inventarios',
    'paz_y_salvo' => 'Paz y salvos',
    'cedula'      => 'Documentos ID',
    'reglamento'  => 'Reglamentos',
    'otro'        => 'Otros',
];

$page_title  = 'Documentos';
$active_menu = 'documents';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Gestión de documentos</div>
    <div class="dpr-page-sub"><?= count($docs) ?> documentos · Contratos, inventarios, paz y salvos</div>
  </div>
  <button class="dpr-btn dpr-btn--primary" onclick="openUploadModal()">+ Subir documento</button>
</div>

<!-- Tabs por tipo -->
<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:18px">
  <a href="?" class="dpr-pill <?= !$f_tipo ? 'dpr-pill--info' : 'dpr-pill--neutral' ?>"
     style="font-size:12px;padding:5px 12px;text-decoration:none">
    Todos (<?= array_sum($cnt) ?>)
  </a>
  <?php foreach ($tipos_doc as $key => $label): ?>
  <a href="?tipo=<?= $key ?>" class="dpr-pill <?= $f_tipo===$key ? 'dpr-pill--info' : 'dpr-pill--neutral' ?>"
     style="font-size:12px;padding:5px 12px;text-decoration:none">
    <?= $label ?> (<?= $cnt[$key] ?? 0 ?>)
  </a>
  <?php endforeach; ?>
</div>

<!-- Filtros adicionales -->
<form method="GET" class="dpr-card" style="padding:12px 18px;margin-bottom:16px">
  <input type="hidden" name="tipo" value="<?= h($f_tipo) ?>">
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
    <div class="dpr-form-group">
      <label class="dpr-label">Inquilino</label>
      <select name="tenant_id" class="dpr-select" style="width:200px">
        <option value="">Todos</option>
        <?php foreach ($all_tenants as $t): ?>
        <option value="<?= $t['id'] ?>" <?= $f_tenant==$t['id']?'selected':'' ?>><?= h($t['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Inmueble</label>
      <select name="property_id" class="dpr-select" style="width:180px">
        <option value="">Todos</option>
        <?php foreach ($all_props as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $f_prop==$p['id']?'selected':'' ?>><?= h($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="dpr-btn dpr-btn--primary">Filtrar</button>
    <a href="?" class="dpr-btn dpr-btn--secondary">Limpiar</a>
  </div>
</form>

<!-- Grid de documentos -->
<?php if (!$docs): ?>
<div class="dpr-card" style="text-align:center;padding:40px">
  <div style="font-size:32px;margin-bottom:12px">📂</div>
  <div style="font-size:15px;color:var(--text-muted)">No hay documentos con los filtros seleccionados.</div>
  <button class="dpr-btn dpr-btn--primary" style="margin-top:16px" onclick="openUploadModal()">Subir primer documento</button>
</div>
<?php else: ?>

<!-- Vista tabla -->
<div class="dpr-card" id="docsTableCard">
  <div class="dpr-table-wrap">
    <table class="dpr-table" id="docsTable">
      <thead>
        <tr>
          <th>Documento</th><th>Tipo</th><th>Inquilino</th>
          <th>Inmueble / Unidad</th><th>Fecha doc.</th>
          <th>Subido</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody id="docsBody">
        <?php foreach ($docs as $d):
          $tipo_label = $tipos_doc[$d['tipo']] ?? ucfirst($d['tipo']);
          $tipo_pill  = match($d['tipo']) {
            'contrato'    => 'info',
            'inventario'  => 'neutral',
            'paz_y_salvo' => 'ok',
            'cedula'      => 'neutral',
            'reglamento'  => 'warn',
            default       => 'neutral',
          };
          $ext = strtolower(pathinfo($d['archivo_url'], PATHINFO_EXTENSION));
          $icon = in_array($ext, ['jpg','jpeg','png','webp']) ? '🖼️' : '📄';
        ?>
        <tr id="doc-row-<?= $d['id'] ?>">
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <span style="font-size:18px"><?= $icon ?></span>
              <div>
                <div style="font-weight:500;color:var(--navy);font-size:13px"><?= h($d['nombre']) ?></div>
                <div style="font-size:11px;color:var(--text-muted)"><?= strtoupper($ext) ?></div>
              </div>
            </div>
          </td>
          <td><span class="dpr-pill dpr-pill--<?= $tipo_pill ?>"><?= $tipo_label ?></span></td>
          <td><?= $d['inquilino'] ? h($d['inquilino']) : '<span class="dpr-text-muted">—</span>' ?></td>
          <td><?= $d['inmueble'] ? h($d['inmueble'].($d['unidad']?' / '.$d['unidad']:'')) : '<span class="dpr-text-muted">—</span>' ?></td>
          <td><?= $d['fecha_doc'] ? fmt_date($d['fecha_doc']) : '<span class="dpr-text-muted">—</span>' ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= fmt_date($d['created_at']) ?></td>
          <td class="dpr-flex">
            <a href="<?= h($d['archivo_url']) ?>" target="_blank"
               class="dpr-btn dpr-btn--sm dpr-btn--secondary" title="Ver / Descargar">
              <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                <path d="M2 9l9-9M11 9V2H4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Ver
            </a>
            <button class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Detalles"
              onclick='openDetailModal(<?= json_encode([
                "id"=>$d['id'],"nombre"=>$d['nombre'],"tipo"=>$d['tipo'],
                "inquilino"=>$d['inquilino'],"unidad"=>$d['unidad'],
                "inmueble"=>$d['inmueble'],"fecha_doc"=>$d['fecha_doc'],
                "archivo_url"=>$d['archivo_url'],"subido_por"=>$d['subido_por_nombre'],
                "created_at"=>$d['created_at']
              ]) ?>)'>
              <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                <circle cx="6.5" cy="6.5" r="5.5" stroke="currentColor" stroke-width="1.2"/>
                <path d="M6.5 5v4M6.5 3.5v.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
              </svg>
            </button>
            <button class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Eliminar"
              style="color:#dc2626"
              onclick="deleteDoc(<?= $d['id'] ?>, '<?= h($d['nombre']) ?>')">
              <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                <path d="M1.5 3.5h10M4.5 3.5V2h4v1.5M5 6v4M8 6v4M2.5 3.5l1 8h6l1-8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ==================== MODAL: Subir documento ==================== -->
<div class="dpr-modal-backdrop" id="uploadModal">
  <div class="dpr-modal" style="width:580px">
    <button class="dpr-modal__close" onclick="closeModal('uploadModal')">&times;</button>
    <div class="dpr-modal__title">Subir documento</div>

    <div id="uploadSuccess" class="dpr-alert dpr-alert--success" style="display:none"></div>
    <div id="uploadError"   class="dpr-alert dpr-alert--danger"  style="display:none"></div>

    <form id="uploadForm" novalidate>
      <div class="dpr-form-grid">
        <div class="dpr-form-group">
          <label class="dpr-label">Tipo de documento *</label>
          <select name="tipo" id="docTipo" class="dpr-select" required onchange="onTipoChange(this)">
            <?php foreach ($tipos_doc as $k=>$v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Nombre descriptivo *</label>
          <input type="text" name="nombre" id="docNombre" class="dpr-input" required
            placeholder="Ej: Contrato firmado 2025">
        </div>

        <div class="dpr-form-group">
          <label class="dpr-label">Inquilino</label>
          <select name="tenant_id" id="docTenant" class="dpr-select" onchange="onTenantChange(this)">
            <option value="">— Sin asociar —</option>
            <?php foreach ($all_tenants as $t): ?>
            <option value="<?= $t['id'] ?>"><?= h($t['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Contrato</label>
          <select name="lease_id" id="docLease" class="dpr-select">
            <option value="">— Sin asociar —</option>
            <?php foreach ($all_leases as $l): ?>
            <option value="<?= $l['id'] ?>"><?= h($l['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="dpr-form-group">
          <label class="dpr-label">Unidad (opcional)</label>
          <select name="unit_id" id="docUnit" class="dpr-select">
            <option value="">— Sin asociar —</option>
            <?php foreach ($all_units as $u): ?>
            <option value="<?= $u['id'] ?>"><?= h($u['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Fecha del documento</label>
          <input type="date" name="fecha_doc" class="dpr-input" value="<?= date('Y-m-d') ?>">
        </div>

        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Archivo * (PDF, imagen · máx. 5 MB)</label>
          <div class="dpr-dropzone" id="dropZone"
               onclick="document.getElementById('docFile').click()"
               ondragover="dragOver(event)" ondragleave="dragLeave(event)" ondrop="dropFile(event)">
            <div id="dropLabel" class="dpr-dropzone__text">Arrastra el archivo aquí o haz clic para seleccionar</div>
            <div style="font-size:12px;color:var(--text-muted)">PDF, JPG, PNG, WEBP</div>
            <div id="dropPreview" style="margin-top:8px;font-size:13px;color:var(--blue);font-weight:500"></div>
          </div>
          <input type="file" id="docFile" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp"
            style="display:none" required onchange="onFileSelect(this)">
        </div>
      </div>

      <!-- Barra de progreso -->
      <div id="progressWrap" style="display:none;margin:12px 0">
        <div style="background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden">
          <div id="progressBar" style="height:100%;background:var(--blue);width:0%;transition:width .2s;border-radius:6px"></div>
        </div>
        <div id="progressLabel" style="font-size:12px;color:var(--text-muted);margin-top:4px;text-align:center">Subiendo...</div>
      </div>

      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeModal('uploadModal')">Cancelar</button>
        <button type="submit" id="submitBtn" class="dpr-btn dpr-btn--primary">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="margin-right:4px">
            <path d="M7 1v8M4 6l3-3 3 3M2 11h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Subir documento
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ==================== MODAL: Detalle de documento ==================== -->
<div class="dpr-modal-backdrop" id="detailModal">
  <div class="dpr-modal" style="width:480px">
    <button class="dpr-modal__close" onclick="closeModal('detailModal')">&times;</button>
    <div class="dpr-modal__title" id="detailTitle"></div>
    <div id="detailContent"></div>
    <div style="margin-top:16px;text-align:right">
      <a id="detailDownload" href="#" target="_blank" class="dpr-btn dpr-btn--primary">Abrir / Descargar</a>
    </div>
  </div>
</div>

<script>
const API_URL = '<?= BASE_URL ?>/api/document_api.php';

// ---- Modal helpers ----
function openUploadModal() {
  document.getElementById('uploadSuccess').style.display = 'none';
  document.getElementById('uploadError').style.display   = 'none';
  document.getElementById('uploadForm').reset();
  document.getElementById('dropPreview').textContent = '';
  document.getElementById('uploadModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.dpr-modal-backdrop').forEach(el =>
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); })
);

// ---- Auto-nombre según tipo ----
function onTipoChange(sel) {
  const labels = <?= json_encode(array_map(fn($v) => $v . ' — ', $tipos_doc)) ?>;
  const inp = document.getElementById('docNombre');
  if (!inp.value) inp.placeholder = (labels[sel.value] || '') + 'Describe el documento';
}

// ---- Auto-fill contrato al seleccionar inquilino ----
function onTenantChange(sel) {
  // Podría filtrarse el select de leases si se quiere autocompletar
}

// ---- Drag & Drop ----
function dragOver(e) { e.preventDefault(); e.currentTarget.classList.add('dragover'); }
function dragLeave(e) { e.currentTarget.classList.remove('dragover'); }
function dropFile(e) {
  e.preventDefault(); e.currentTarget.classList.remove('dragover');
  if (e.dataTransfer.files.length) {
    document.getElementById('docFile').files = e.dataTransfer.files;
    onFileSelect(document.getElementById('docFile'));
  }
}
function onFileSelect(input) {
  const f = input.files[0];
  if (!f) return;
  const mb = (f.size / 1024 / 1024).toFixed(2);
  document.getElementById('dropPreview').textContent = `${f.name} (${mb} MB)`;
  // Auto-fill nombre si está vacío
  const nombre = document.getElementById('docNombre');
  if (!nombre.value) {
    nombre.value = f.name.replace(/\.[^.]+$/, '').replace(/[_-]/g, ' ');
  }
}

// ---- AJAX Upload con progreso ----
document.getElementById('uploadForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const file = document.getElementById('docFile').files[0];
  if (!file) {
    showError('Debes seleccionar un archivo.');
    return;
  }

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = 'Subiendo...';

  const fd = new FormData(this);
  fd.append('action', 'upload');

  const xhr = new XMLHttpRequest();

  // Progreso de subida
  document.getElementById('progressWrap').style.display = 'block';
  xhr.upload.addEventListener('progress', function(ev) {
    if (ev.lengthComputable) {
      const pct = Math.round(ev.loaded / ev.total * 100);
      document.getElementById('progressBar').style.width  = pct + '%';
      document.getElementById('progressLabel').textContent = `Subiendo... ${pct}%`;
    }
  });

  xhr.addEventListener('load', function() {
    btn.disabled = false;
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="margin-right:4px"><path d="M7 1v8M4 6l3-3 3 3M2 11h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>Subir documento';
    document.getElementById('progressWrap').style.display = 'none';
    document.getElementById('progressBar').style.width = '0%';

    try {
      const res = JSON.parse(xhr.responseText);
      if (res.success) {
        showSuccess(res.message || 'Documento subido correctamente.');
        // Insertar fila en tabla sin recargar
        prependDocRow(res);
        // Limpiar form
        document.getElementById('uploadForm').reset();
        document.getElementById('dropPreview').textContent = '';
        // Cerrar modal después de 1.5s
        setTimeout(() => closeModal('uploadModal'), 1500);
      } else {
        showError(res.message || 'Error al subir el documento.');
      }
    } catch(ex) {
      showError('Respuesta inesperada del servidor.');
    }
  });

  xhr.addEventListener('error', function() {
    btn.disabled = false;
    showError('Error de red. Verifica tu conexión.');
  });

  xhr.open('POST', API_URL);
  xhr.send(fd);
});

function showSuccess(msg) {
  const el = document.getElementById('uploadSuccess');
  el.textContent = msg; el.style.display = 'flex';
  document.getElementById('uploadError').style.display = 'none';
}
function showError(msg) {
  const el = document.getElementById('uploadError');
  el.textContent = msg; el.style.display = 'flex';
  document.getElementById('uploadSuccess').style.display = 'none';
}

// ---- Insertar fila nueva en la tabla sin recarga ----
function prependDocRow(doc) {
  const tbody = document.getElementById('docsBody');
  if (!tbody) return;
  const tipos = <?= json_encode($tipos_doc) ?>;
  const pills = {contrato:'info',inventario:'neutral',paz_y_salvo:'ok',cedula:'neutral',reglamento:'warn',otro:'neutral'};
  const ext   = (doc.archivo_url || '').split('.').pop().toLowerCase();
  const icon  = ['jpg','jpeg','png','webp'].includes(ext) ? '🖼️' : '📄';
  const pill  = pills[doc.tipo] || 'neutral';
  const label = tipos[doc.tipo] || doc.tipo;

  const tr = document.createElement('tr');
  tr.id = 'doc-row-' + doc.doc_id;
  tr.innerHTML = `
    <td>
      <div style="display:flex;align-items:center;gap:8px">
        <span style="font-size:18px">${icon}</span>
        <div>
          <div style="font-weight:500;color:var(--navy);font-size:13px">${escHtml(doc.nombre)}</div>
          <div style="font-size:11px;color:var(--text-muted)">${ext.toUpperCase()}</div>
        </div>
      </div>
    </td>
    <td><span class="dpr-pill dpr-pill--${pill}">${label}</span></td>
    <td><span class="dpr-text-muted">—</span></td>
    <td><span class="dpr-text-muted">—</span></td>
    <td>${doc.fecha_doc || '—'}</td>
    <td style="font-size:12px;color:var(--text-muted)">Ahora</td>
    <td class="dpr-flex">
      <a href="${escHtml(doc.archivo_url)}" target="_blank" class="dpr-btn dpr-btn--sm dpr-btn--secondary">
        <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M2 9l9-9M11 9V2H4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg> Ver
      </a>
      <button class="dpr-btn dpr-btn--sm dpr-btn--icon" style="color:#dc2626"
        onclick="deleteDoc(${doc.doc_id}, '${escHtml(doc.nombre)}')">
        <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M1.5 3.5h10M4.5 3.5V2h4v1.5M5 6v4M8 6v4M2.5 3.5l1 8h6l1-8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
    </td>`;

  // Highlight breve
  tr.style.background = '#eff6ff';
  tbody.insertBefore(tr, tbody.firstChild);
  setTimeout(() => { tr.style.transition = 'background 1s'; tr.style.background = ''; }, 100);
}

// ---- Eliminar documento (AJAX) ----
function deleteDoc(id, nombre) {
  if (!confirm(`¿Eliminar el documento "${nombre}"? Esta acción no se puede deshacer.`)) return;

  const fd = new FormData();
  fd.append('action',  'delete');
  fd.append('doc_id',  id);

  fetch(API_URL, { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        const row = document.getElementById('doc-row-' + id);
        if (row) {
          row.style.transition = 'opacity .3s';
          row.style.opacity = '0';
          setTimeout(() => row.remove(), 300);
        }
      } else {
        alert('Error: ' + res.message);
      }
    })
    .catch(() => alert('Error de red al eliminar.'));
}

// ---- Modal de detalle ----
function openDetailModal(doc) {
  document.getElementById('detailTitle').textContent = doc.nombre;
  const tipos = <?= json_encode($tipos_doc) ?>;
  document.getElementById('detailContent').innerHTML = `
    <div style="display:flex;flex-direction:column;gap:10px;font-size:13px">
      ${row('Tipo',             tipos[doc.tipo] || doc.tipo)}
      ${row('Inquilino',        doc.inquilino || '—')}
      ${row('Inmueble/Unidad',  doc.inmueble ? (doc.inmueble + (doc.unidad?' / '+doc.unidad:'')) : '—')}
      ${row('Fecha documento',  doc.fecha_doc || '—')}
      ${row('Subido por',       doc.subido_por || '—')}
      ${row('Fecha de subida',  doc.created_at || '—')}
    </div>`;
  document.getElementById('detailDownload').href = doc.archivo_url;
  document.getElementById('detailModal').classList.add('open');
}
function row(label, val) {
  return `<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:0.5px solid var(--border)">
            <span style="color:var(--text-muted)">${label}</span>
            <span style="font-weight:500">${escHtml(String(val))}</span>
          </div>`;
}
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
