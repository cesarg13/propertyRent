<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Fetch public units (visible=1, estado=disponible)
$units = db_query($mysqli,
  "SELECT u.id, u.nombre, u.tipo, u.piso, u.area_m2, u.valor_arriendo, u.descripcion,
          u.imagen_url, u.video_embed,
          p.nombre AS inmueble, p.ciudad, p.direccion
   FROM units u
   JOIN properties p ON p.id = u.property_id
   WHERE u.estado = 'disponible' AND u.publicar = 1 AND p.estado = 'activo'
   ORDER BY p.nombre, u.valor_arriendo ASC");

$tipo_labels = ['apartamento'=>'Apartamento','local'=>'Local','habitacion'=>'Habitación','oficina'=>'Oficina','bodega'=>'Bodega','otro'=>'Otro'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Unidades disponibles — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    body{background:var(--gray-bg);margin:0}
    .pu-nav{background:var(--navy);padding:16px 32px;display:flex;align-items:center;justify-content:space-between}
    .pu-nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
    .pu-nav-brand span{color:#fff;font-size:16px;font-weight:700}
    .pu-nav-links{display:flex;gap:20px;align-items:center}
    .pu-nav-links a{color:rgba(255,255,255,.7);text-decoration:none;font-size:13px;transition:color .15s}
    .pu-nav-links a:hover{color:#fff}
    .pu-nav-links .btn{background:var(--blue);color:#fff;padding:8px 18px;border-radius:var(--radius-sm);font-weight:600}
    .pu-nav-links .btn:hover{background:#155099}
    .pu-hero{background:linear-gradient(135deg,var(--navy),var(--blue));padding:52px 32px;text-align:center}
    .pu-hero h1{color:#fff;font-size:clamp(22px,3vw,36px);font-weight:800;margin-bottom:10px;letter-spacing:-.5px}
    .pu-hero p{color:rgba(255,255,255,.72);font-size:15px;max-width:540px;margin:0 auto}
    .pu-body{max-width:1180px;margin:0 auto;padding:40px 32px 80px}
    .pu-filters{background:#fff;border:.5px solid var(--border);border-radius:var(--radius-lg);padding:18px 20px;margin-bottom:28px;display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}
    .pu-filter-group{display:flex;flex-direction:column;gap:4px;min-width:140px}
    .pu-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:22px}
    .pu-card{background:#fff;border:.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;transition:transform .18s,box-shadow .18s}
    .pu-card:hover{transform:translateY(-4px);box-shadow:0 10px 36px rgba(15,45,82,.12)}
    .pu-img{width:100%;height:190px;object-fit:cover;display:block;background:var(--gray-card)}
    .pu-img-placeholder{width:100%;height:190px;background:linear-gradient(135deg,var(--blue-lt),#dbeafe);display:flex;align-items:center;justify-content:center;color:var(--blue)}
    .pu-body-pad{padding:18px 18px 20px}
    .pu-tipo{font-size:11px;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px}
    .pu-name{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:3px}
    .pu-loc{font-size:12px;color:var(--text-muted);margin-bottom:12px;display:flex;align-items:center;gap:4px}
    .pu-specs{display:flex;gap:14px;margin-bottom:14px;flex-wrap:wrap}
    .pu-spec{font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:4px}
    .pu-price{font-size:20px;font-weight:800;color:var(--navy);margin-bottom:14px}
    .pu-price span{font-size:12px;color:var(--text-muted);font-weight:400}
    .pu-desc{font-size:12px;color:var(--text-muted);line-height:1.6;margin-bottom:14px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .pu-actions{display:flex;gap:8px}
    .pu-video-wrap{width:100%;height:190px;position:relative;background:#000}
    .pu-video-wrap iframe{width:100%;height:100%;border:none}
    .pu-empty{text-align:center;padding:80px 20px;color:var(--text-muted)}
    .pu-empty h3{font-size:18px;font-weight:600;color:var(--navy);margin-bottom:8px}
    .pu-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:900;align-items:center;justify-content:center}
    .pu-modal-backdrop.open{display:flex}
    .pu-modal{background:#fff;border-radius:var(--radius-lg);padding:0;width:640px;max-width:96vw;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.25)}
    .pu-modal-img{width:100%;height:240px;object-fit:cover;display:block}
    .pu-modal-video{width:100%;height:240px}
    .pu-modal-video iframe{width:100%;height:100%;border:none}
    .pu-modal-body{padding:24px}
    .pu-modal-close{float:right;background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-muted);margin-top:-4px}
    .lp-wa{position:fixed;bottom:26px;right:26px;width:54px;height:54px;background:#25d366;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 20px rgba(37,211,102,.4);text-decoration:none;z-index:9999;transition:transform .2s}
    .lp-wa:hover{transform:scale(1.1)}
    @media(max-width:640px){.pu-filters{flex-direction:column}.pu-filter-group{min-width:100%}}
  </style>
</head>
<body>
<nav class="pu-nav">
  <a href="<?= BASE_URL ?>" class="pu-nav-brand">
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M2 18V8L10 2l8 6v10H13v-6H7v6H2Z" stroke="white" stroke-width="1.5" stroke-linejoin="round"/></svg>
    <span><?= APP_NAME ?></span>
  </a>
  <div class="pu-nav-links">
    <a href="<?= BASE_URL ?>/#caracteristicas">Características</a>
    <a href="<?= BASE_URL ?>/#precios">Precios</a>
    <a href="<?= BASE_URL ?>/#contacto">Contacto</a>
    <a href="<?= BASE_URL ?>" class="btn">Iniciar sesión</a>
  </div>
</nav>

<div class="pu-hero">
  <h1>Unidades disponibles para arrendar</h1>
  <p>Encuentra el espacio ideal para vivir o trabajar. Todas las unidades gestionadas con <?= APP_NAME ?>.</p>
</div>

<div class="pu-body">
  <!-- Filtros -->
  <div class="pu-filters">
    <div class="pu-filter-group">
      <label class="dpr-label">Tipo de inmueble</label>
      <select id="fTipo" class="dpr-select" onchange="filterUnits()">
        <option value="">Todos</option>
        <?php foreach ($tipo_labels as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="pu-filter-group">
      <label class="dpr-label">Ciudad</label>
      <select id="fCiudad" class="dpr-select" onchange="filterUnits()">
        <option value="">Todas</option>
        <?php
        $ciudades = array_unique(array_column($units,'ciudad'));
        sort($ciudades);
        foreach ($ciudades as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="pu-filter-group">
      <label class="dpr-label">Precio máximo</label>
      <input type="number" id="fPrecio" class="dpr-input" placeholder="Ej: 2000000" step="100000" oninput="filterUnits()">
    </div>
    <button class="dpr-btn dpr-btn--secondary dpr-btn--sm" onclick="clearFilters()">Limpiar filtros</button>
  </div>

  <?php if (!$units): ?>
  <div class="pu-empty">
    <svg width="48" height="48" viewBox="0 0 48 48" fill="none" style="margin-bottom:16px;color:var(--text-muted)"><path d="M6 42V18L24 6l18 12v24H31V28H17v14H6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
    <h3>No hay unidades disponibles en este momento</h3>
    <p>Vuelve pronto o <a href="#contacto" style="color:var(--blue)">contáctanos</a> para más información.</p>
  </div>
  <?php else: ?>
  <div class="pu-grid" id="unidadesGrid">
    <?php foreach ($units as $u):
      $img = $u['imagen_url'] ? h($u['imagen_url']) : '';
      $video = $u['video_embed'] ?? '';
    ?>
    <div class="pu-card"
         data-tipo="<?= h($u['tipo']) ?>"
         data-ciudad="<?= h($u['ciudad']) ?>"
         data-precio="<?= (int)$u['valor_arriendo'] ?>">
      <?php if ($video): ?>
        <div class="pu-video-wrap"><?= $video ?></div>
      <?php elseif ($img): ?>
        <img src="<?= $img ?>" alt="<?= h($u['nombre']) ?>" class="pu-img" loading="lazy">
      <?php else: ?>
        <div class="pu-img-placeholder">
          <svg width="40" height="40" viewBox="0 0 40 40" fill="none"><path d="M4 36V14L20 4l16 10v22H26V22H14v14H4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
        </div>
      <?php endif; ?>
      <div class="pu-body-pad">
        <div class="pu-tipo"><?= h($tipo_labels[$u['tipo']] ?? $u['tipo']) ?></div>
        <div class="pu-name"><?= h($u['nombre']) ?></div>
        <div class="pu-loc">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><circle cx="6" cy="5" r="2" stroke="currentColor" stroke-width="1.1"/><path d="M6 1a4 4 0 0 1 4 4c0 3-4 7-4 7S2 8 2 5a4 4 0 0 1 4-4z" stroke="currentColor" stroke-width="1.1"/></svg>
          <?= h($u['inmueble']) ?> · <?= h($u['ciudad']) ?>
        </div>
        <div class="pu-specs">
          <?php if ($u['area_m2']): ?><div class="pu-spec">📐 <?= (float)$u['area_m2'] ?> m²</div><?php endif; ?>
          <?php if ($u['piso']): ?><div class="pu-spec">🏢 Piso <?= (int)$u['piso'] ?></div><?php endif; ?>
        </div>
        <div class="pu-price"><?= fmt_money((float)$u['valor_arriendo']) ?> <span>/ mes</span></div>
        <?php if ($u['descripcion']): ?><div class="pu-desc"><?= h($u['descripcion']) ?></div><?php endif; ?>
        <div class="pu-actions">
          <button class="dpr-btn dpr-btn--primary dpr-btn--sm" onclick="openDetail(<?= $u['id'] ?>)">Ver detalle</button>
          <a href="https://wa.me/<?= $whatsapp_number ?>?text=Hola%2C+me+interesa+la+unidad+<?= rawurlencode($u['nombre'].' - '.$u['inmueble']) ?>" target="_blank" class="dpr-btn dpr-btn--secondary dpr-btn--sm">WhatsApp</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div id="noResults" style="display:none" class="pu-empty">
    <h3>Sin resultados</h3><p>Ajusta los filtros para ver más opciones.</p>
  </div>
  <?php endif; ?>
</div>

<!-- Modal detalle -->
<div class="pu-modal-backdrop" id="detailModal">
  <div class="pu-modal">
    <div id="modalContent"></div>
  </div>
</div>

<a href="https://wa.me/573000000000?text=Hola%2C+me+interesan+las+unidades+disponibles" target="_blank" class="lp-wa" title="WhatsApp">
  <svg width="28" height="28" viewBox="0 0 28 28" fill="none"><path d="M14 2C7.373 2 2 7.373 2 14c0 2.124.553 4.12 1.522 5.851L2 26l6.355-1.496A11.94 11.94 0 0 0 14 26c6.627 0 12-5.373 12-12S20.627 2 14 2z" fill="white"/><path d="M19.823 17.265c-.262-.131-1.55-.763-1.79-.85-.239-.088-.413-.131-.587.132-.174.262-.674.85-.826 1.024-.152.175-.304.197-.567.066-.262-.131-1.107-.407-2.108-1.298-.779-.694-1.305-1.55-1.458-1.812-.152-.262-.016-.404.115-.534.117-.117.262-.305.393-.457.131-.153.174-.262.262-.436.087-.175.043-.328-.022-.46-.066-.13-.587-1.414-.804-1.937-.212-.508-.427-.44-.587-.447-.152-.007-.327-.009-.502-.009s-.458.066-.698.328c-.24.262-.915.895-.915 2.18s.937 2.528 1.068 2.702c.13.175 1.844 2.815 4.468 3.948.624.27 1.11.43 1.49.55.626.2 1.195.171 1.644.104.501-.075 1.55-.633 1.768-1.245.218-.612.218-1.137.152-1.245-.065-.109-.239-.175-.502-.306z" fill="#25D366"/></svg>
</a>

<?php
// Build JS data for modal
$units_js = [];
foreach ($units as $u) {
    $units_js[$u['id']] = [
        'nombre'    => $u['nombre'],
        'tipo'      => $tipo_labels[$u['tipo']] ?? $u['tipo'],
        'inmueble'  => $u['inmueble'],
        'ciudad'    => $u['ciudad'],
        'direccion' => $u['direccion'],
        'area'      => $u['area_m2'],
        'piso'      => $u['piso'],
        'precio'    => fmt_money((float)$u['valor_arriendo']),
        'desc'      => $u['descripcion'],
        'img'       => $u['imagen_url'],
        'video'     => $u['video_embed'] ?? '',
    ];
}
?>
<script>
const unitsData = <?= json_encode($units_js, JSON_UNESCAPED_UNICODE) ?>;

function openDetail(id) {
  const u = unitsData[id]; if (!u) return;
  const media = u.video
    ? `<div class="pu-modal-video">${u.video}</div>`
    : u.img
      ? `<img src="${u.img}" class="pu-modal-img" alt="${u.nombre}">`
      : `<div style="height:200px;background:var(--blue-lt);display:flex;align-items:center;justify-content:center;color:var(--blue)"><svg width="48" height="48" viewBox="0 0 48 48" fill="none"><path d="M6 42V18L24 6l18 12v24H31V28H17v14H6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg></div>`;
  document.getElementById('modalContent').innerHTML = `
    ${media}
    <div class="pu-modal-body">
      <button class="pu-modal-close" onclick="closeDetail()">&times;</button>
      <div style="font-size:11px;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px">${u.tipo}</div>
      <div style="font-size:20px;font-weight:700;color:var(--navy);margin-bottom:4px">${u.nombre}</div>
      <div style="font-size:13px;color:var(--text-muted);margin-bottom:14px">📍 ${u.inmueble} · ${u.ciudad}</div>
      ${u.area ? `<div style="font-size:13px;color:var(--text-muted);margin-bottom:6px">📐 Área: ${u.area} m²${u.piso ? ' &nbsp;·&nbsp; 🏢 Piso '+u.piso : ''}</div>` : ''}
      <div style="font-size:13px;color:var(--text-muted);margin-bottom:14px">📍 ${u.direccion}</div>
      <div style="font-size:26px;font-weight:800;color:var(--navy);margin-bottom:14px">${u.precio} <span style="font-size:13px;font-weight:400;color:var(--text-muted)">/ mes</span></div>
      ${u.desc ? `<p style="font-size:13px;color:var(--text);line-height:1.7;margin-bottom:18px">${u.desc}</p>` : ''}
      <a href="https://wa.me/573000000000?text=Hola%2C+me+interesa+la+unidad+${encodeURIComponent(u.nombre+' - '+u.inmueble)}" target="_blank" class="dpr-btn dpr-btn--primary" style="width:100%;justify-content:center;padding:12px">
        Contactar por WhatsApp
      </a>
    </div>`;
  document.getElementById('detailModal').classList.add('open');
}
function closeDetail() { document.getElementById('detailModal').classList.remove('open'); }
document.getElementById('detailModal').addEventListener('click', e => { if (e.target === document.getElementById('detailModal')) closeDetail(); });

function filterUnits() {
  const tipo   = document.getElementById('fTipo').value;
  const ciudad = document.getElementById('fCiudad').value;
  const precio = parseFloat(document.getElementById('fPrecio').value) || Infinity;
  let visible  = 0;
  document.querySelectorAll('#unidadesGrid .pu-card').forEach(card => {
    const ok = (!tipo || card.dataset.tipo === tipo)
      && (!ciudad || card.dataset.ciudad === ciudad)
      && (parseFloat(card.dataset.precio) <= precio);
    card.style.display = ok ? '' : 'none';
    if (ok) visible++;
  });
  document.getElementById('noResults').style.display = visible === 0 ? 'block' : 'none';
}
function clearFilters() {
  document.getElementById('fTipo').value   = '';
  document.getElementById('fCiudad').value = '';
  document.getElementById('fPrecio').value = '';
  filterUnits();
}
</script>
</body>
</html>
