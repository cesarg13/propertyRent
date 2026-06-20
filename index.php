<?php
// =============================================================
//  PropertyRent — Landing page + Login
// =============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Si ya está logueado, redirigir al panel
if (isset($_SESSION['dpr_user_id'])) {
    $dest = $_SESSION['dpr_rol'] === 'admin'
        ? BASE_URL . '/admin/dashboard.php'
        : BASE_URL . '/tenant/dashboard.php';
    header("Location: $dest"); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    if (!$email || !$password) {
        $error = 'Ingresa tu correo y contraseña.';
    } else {
        $rows = db_query($mysqli,
            "SELECT id,nombre,apellido,email,password_hash,rol,estado FROM users WHERE email=? LIMIT 1",
            's', [$email]);
        if (!$rows || !password_verify($password, $rows[0]['password_hash'])) {
            $error = 'Credenciales incorrectas.'; sleep(1);
        } elseif ($rows[0]['estado'] !== 'activo') {
            $error = 'Tu cuenta está suspendida. Contacta al administrador.';
        } else {
            $u = $rows[0];
            session_regenerate_id(true);
            $_SESSION['dpr_user_id']       = $u['id'];
            $_SESSION['dpr_nombre']        = $u['nombre'] . ' ' . $u['apellido'];
            $_SESSION['dpr_email']         = $u['email'];
            $_SESSION['dpr_rol']           = $u['rol'];
            $_SESSION['dpr_last_activity'] = time();
            $dest = $u['rol'] === 'admin'
                ? BASE_URL . '/admin/dashboard.php'
                : BASE_URL . '/tenant/dashboard.php';
            header("Location: $dest"); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= APP_NAME ?> — Gestión de arrendamientos</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    body{background:var(--gray-bg);margin:0}
    .lp-hero{min-height:100vh;display:grid;grid-template-columns:1fr 1fr;background:linear-gradient(135deg,var(--navy) 0%,#163668 55%,var(--blue) 100%);position:relative;overflow:hidden}
    .lp-hero::before{content:'';position:absolute;inset:0;background-image:radial-gradient(circle at 20% 80%,rgba(255,255,255,.04) 0%,transparent 50%),radial-gradient(circle at 80% 20%,rgba(96,180,255,.08) 0%,transparent 50%);pointer-events:none}
    .lp-hl{display:flex;flex-direction:column;justify-content:center;padding:64px 48px 64px 72px;position:relative;z-index:1}
    .lp-hr{display:flex;align-items:center;justify-content:center;padding:64px 72px 64px 40px;position:relative;z-index:1}
    .lp-logo-row{display:flex;align-items:center;gap:12px;margin-bottom:52px}
    .lp-logo-ico{width:46px;height:46px;background:rgba(255,255,255,.12);border-radius:12px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.18)}
    .lp-app-name{color:#fff;font-size:19px;font-weight:700}
    .lp-app-sub{color:rgba(255,255,255,.45);font-size:10px;letter-spacing:1.2px;text-transform:uppercase}
    .lp-h1{color:#fff;font-size:clamp(28px,3.2vw,48px);font-weight:800;line-height:1.13;letter-spacing:-1.2px;margin-bottom:20px}
    .lp-h1 em{color:#7dceff;font-style:normal}
    .lp-p{color:rgba(255,255,255,.72);font-size:15px;line-height:1.75;margin-bottom:32px;max-width:430px}
    .lp-bullets{list-style:none;margin-bottom:40px;display:flex;flex-direction:column;gap:9px}
    .lp-bullets li{color:rgba(255,255,255,.82);font-size:13px;display:flex;align-items:center;gap:10px}
    .lp-bullets li::before{content:'';width:18px;height:18px;border-radius:50%;border:1.5px solid rgba(125,206,255,.5);background:rgba(125,206,255,.12) url('data:image/svg+xml,%3Csvg viewBox%3D%220 0 10 10%22 xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Cpath d%3D%22M2 5l2.5 2.5L8 3%22 stroke%3D%22%237dceff%22 stroke-width%3D%221.5%22 stroke-linecap%3D%22round%22 stroke-linejoin%3D%22round%22 fill%3D%22none%22%2F%3E%3C%2Fsvg%3E') no-repeat center;flex-shrink:0}
    .lp-cta{display:inline-flex;align-items:center;gap:8px;background:#fff;color:var(--navy);font-size:14px;font-weight:700;padding:14px 28px;border-radius:var(--radius);text-decoration:none;box-shadow:0 8px 30px rgba(0,0,0,.25);transition:transform .15s,box-shadow .15s;border:none;cursor:pointer}
    .lp-cta:hover{transform:translateY(-2px);box-shadow:0 14px 40px rgba(0,0,0,.3)}
    /* LOGIN CARD */
    .lp-card{background:rgba(255,255,255,.97);border-radius:var(--radius-lg);padding:40px 36px;width:100%;max-width:420px;box-shadow:0 28px 80px rgba(0,0,0,.28)}
    .lp-card h2{font-size:20px;font-weight:700;color:var(--navy);margin-bottom:3px}
    .lp-card .sub{font-size:13px;color:var(--text-muted);margin-bottom:26px}
    .lp-google{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:11px;border:1.5px solid var(--border-md);border-radius:var(--radius-sm);background:#fff;color:var(--text);font-size:13px;font-weight:500;cursor:pointer;transition:background .12s,border-color .12s;text-decoration:none}
    .lp-google:hover{background:var(--gray-card);border-color:var(--blue)}
    .lp-divider{display:flex;align-items:center;gap:10px;margin:18px 0;font-size:11px;color:var(--text-muted)}
    .lp-divider::before,.lp-divider::after{content:'';flex:1;height:1px;background:var(--border)}
    .lp-forgot{display:block;text-align:right;font-size:12px;color:var(--blue);text-decoration:none;margin-top:-6px;margin-bottom:16px}
    .lp-forgot:hover{text-decoration:underline}
    .lp-fbox{display:none;background:var(--info-bg);border-radius:var(--radius-sm);padding:14px;margin-bottom:14px;font-size:13px;color:var(--info-tx)}
    .lp-fbox.open{display:block}
    /* SECTIONS */
    .lp-sec{padding:80px 0}.lp-sec--alt{background:#fff}
    .lp-wrap{max-width:1180px;margin:0 auto;padding:0 32px}
    .lp-label{font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--blue);margin-bottom:8px}
    .lp-h2{font-size:clamp(22px,2.5vw,36px);font-weight:800;color:var(--navy);letter-spacing:-.5px;margin-bottom:12px}
    .lp-sub{font-size:15px;color:var(--text-muted);max-width:560px;line-height:1.75;margin-bottom:48px}
    /* FEATURES */
    .lp-fg{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
    .lp-fc{background:var(--gray-card);border:.5px solid var(--border);border-radius:var(--radius-lg);padding:26px 22px;transition:transform .18s,box-shadow .18s}
    .lp-fc:hover{transform:translateY(-4px);box-shadow:0 6px 24px rgba(15,45,82,.1)}
    .lp-fi{width:44px;height:44px;border-radius:var(--radius);background:var(--blue-lt);display:flex;align-items:center;justify-content:center;margin-bottom:14px;color:var(--blue)}
    .lp-fc h3{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:7px}
    .lp-fc p{font-size:13px;color:var(--text-muted);line-height:1.65}
    /* MOCK */
    .lp-mock{background:#fff;border:.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;box-shadow:0 10px 40px rgba(15,45,82,.1)}
    .lp-mhdr{background:var(--navy);padding:10px 16px;display:flex;align-items:center;gap:6px}
    .lp-dot{width:8px;height:8px;border-radius:50%}
    .lp-mbody{padding:18px 20px}
    .lp-krow{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
    .lp-kpi{background:var(--gray-card);border:.5px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;border-left:3px solid var(--blue)}
    .lp-kpi--g{border-left-color:#10b981}.lp-kpi--y{border-left-color:#f59e0b}.lp-kpi--r{border-left-color:#ef4444}
    .lp-kpi .kl{font-size:9px;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px}
    .lp-kpi .kv{font-size:15px;font-weight:700;color:var(--navy)}
    .lp-mt{width:100%;border-collapse:collapse;font-size:12px}
    .lp-mt th{text-align:left;padding:8px 12px;font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;background:var(--gray-card);border-bottom:.5px solid var(--border)}
    .lp-mt td{padding:9px 12px;border-bottom:.5px solid var(--border);color:var(--text)}
    .lp-pp{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:500}
    .lp-pp-ok{background:var(--success-bg);color:var(--success-tx)}.lp-pp-w{background:var(--warn-bg);color:var(--warn-tx)}.lp-pp-d{background:var(--danger-bg);color:var(--danger-tx)}
    /* PRICING */
    .lp-pg{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;align-items:start}
    .lp-pc{background:#fff;border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:32px 26px;position:relative}
    .lp-pc--feat{border-color:var(--blue);box-shadow:0 8px 40px rgba(26,95,168,.15)}
    .lp-pbadge{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--blue);color:#fff;font-size:11px;font-weight:700;padding:3px 14px;border-radius:20px;white-space:nowrap}
    .lp-pname{font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px}
    .lp-pamt{font-size:36px;font-weight:800;color:var(--navy);line-height:1;margin-bottom:4px}
    .lp-pamt span{font-size:14px;font-weight:400;color:var(--text-muted)}
    .lp-pdesc{font-size:13px;color:var(--text-muted);margin-bottom:20px;line-height:1.5}
    .lp-plist{list-style:none;margin-bottom:24px}
    .lp-plist li{font-size:13px;color:var(--text);padding:7px 0;border-bottom:.5px solid var(--border);display:flex;align-items:flex-start;gap:7px}
    .lp-plist li:last-child{border-bottom:none}
    .lp-plist li::before{content:'✓';color:#10b981;font-weight:700;flex-shrink:0;margin-top:1px}
    .lp-pbtn{display:block;text-align:center;padding:12px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;text-decoration:none;transition:background .12s}
    .lp-pbtn--o{border:1.5px solid var(--blue);color:var(--blue)}.lp-pbtn--o:hover{background:var(--blue-lt)}
    .lp-pbtn--s{background:var(--blue);color:#fff;border:1.5px solid var(--blue)}.lp-pbtn--s:hover{background:#155099}
    /* CAROUSEL */
    .lp-ts{overflow:hidden}
    .lp-tt{display:flex;gap:22px;transition:transform .7s cubic-bezier(.4,0,.2,1)}
    .lp-tc{background:#fff;border:.5px solid var(--border);border-radius:var(--radius-lg);padding:26px 22px;min-width:330px;max-width:330px;flex-shrink:0}
    .lp-tstars{color:#f59e0b;font-size:13px;margin-bottom:10px}
    .lp-ttext{font-size:13px;color:var(--text);line-height:1.7;margin-bottom:14px;font-style:italic}
    .lp-tau{display:flex;align-items:center;gap:10px}
    .lp-tav{width:34px;height:34px;border-radius:50%;background:var(--blue-lt);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;color:var(--blue);flex-shrink:0}
    .lp-tn{font-size:13px;font-weight:600;color:var(--navy)}
    .lp-tr{font-size:11px;color:var(--text-muted)}
    .lp-dots{display:flex;justify-content:center;gap:7px;margin-top:26px}
    .lp-db{width:7px;height:7px;border-radius:50%;background:var(--border-md);border:none;cursor:pointer;transition:background .2s,transform .2s;padding:0}
    .lp-db.on{background:var(--blue);transform:scale(1.35)}
    .lp-avail{display:inline-flex;align-items:center;gap:8px;background:var(--navy);color:#fff;padding:13px 26px;border-radius:var(--radius);font-size:14px;font-weight:600;text-decoration:none;transition:background .15s;margin-top:38px}
    .lp-avail:hover{background:var(--blue)}
    /* CONTACT */
    .lp-cg{display:grid;grid-template-columns:1fr 1.4fr;gap:48px;align-items:start}
    .lp-ci h3{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:6px}
    .lp-ci p{font-size:14px;color:var(--text-muted);margin-bottom:24px;line-height:1.6}
    .lp-cd{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px}
    .lp-cd-ico{width:36px;height:36px;background:var(--blue-lt);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--blue);flex-shrink:0}
    .lp-cd-txt{font-size:13px;color:var(--text);line-height:1.5}
    .lp-cd-txt strong{display:block;color:var(--navy);font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:1px}
    .lp-cf{background:#fff;border:.5px solid var(--border);border-radius:var(--radius-lg);padding:32px 28px}
    /* FOOTER */
    .lp-foot{background:var(--navy);color:rgba(255,255,255,.5);padding:28px 0;font-size:13px}
    .lp-foot-in{max-width:1180px;margin:0 auto;padding:0 32px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
    .lp-foot a{color:rgba(255,255,255,.65);text-decoration:none}.lp-foot a:hover{color:#fff}
    /* WA */
    .lp-wa{position:fixed;bottom:26px;right:26px;width:54px;height:54px;background:#25d366;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 20px rgba(37,211,102,.4);text-decoration:none;z-index:9999;transition:transform .2s}
    .lp-wa:hover{transform:scale(1.1)}
    .lp-wa-tip{position:absolute;right:64px;background:#fff;color:var(--text);font-size:12px;font-weight:500;padding:6px 12px;border-radius:8px;white-space:nowrap;box-shadow:0 4px 16px rgba(0,0,0,.12);opacity:0;pointer-events:none;transition:opacity .2s}
    .lp-wa:hover .lp-wa-tip{opacity:1}
    @media(max-width:960px){.lp-hero{grid-template-columns:1fr}.lp-hl{padding:48px 28px 20px}.lp-hr{padding:20px 28px 48px}.lp-fg,.lp-pg{grid-template-columns:1fr}.lp-krow{grid-template-columns:repeat(2,1fr)}.lp-cg{grid-template-columns:1fr}.lp-foot-in{flex-direction:column;text-align:center}}
    @media(max-width:600px){.lp-h1{font-size:26px}.lp-tc{min-width:270px;max-width:270px}.lp-sec{padding:52px 0}}
  </style>
</head>
<body>

<!-- HERO -->
<section class="lp-hero" id="inicio">
  <div class="lp-hl">
    <div class="lp-logo-row">
      <div class="lp-logo-ico">
        <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M2 20V9L11 2l9 7v11H15v-7H7v7H2Z" stroke="white" stroke-width="1.8" stroke-linejoin="round"/></svg>
      </div>
      <div><div class="lp-app-name"><?= APP_NAME ?></div><div class="lp-app-sub">Gestión de arrendamientos</div></div>
    </div>
    <h1 class="lp-h1">El más potente<br>gestor de<br><em>arrendamientos.</em></h1>
    <p class="lp-p">Alquila tus propiedades. Administra con eficiencia. Controla pagos, contratos, servicios y más desde un solo lugar — con portal para tus inquilinos incluido.</p>
    <ul class="lp-bullets">
      <li>Pagos parciales, abonos y mora automática</li>
      <li>Portal propio para inquilinos</li>
      <li>Servicios públicos y medidores integrados</li>
      <li>Documentos y contratos digitales</li>
    </ul>
    <a href="#caracteristicas" class="lp-cta">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M3 9l5 5 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Ver características
    </a>
  </div>

  <!-- LOGIN -->
  <div class="lp-hr">
    <div class="lp-card">
      <h2>Bienvenido</h2>
      <p class="sub">Accede a tu panel de administración</p>
      <?php if ($error): ?><div class="dpr-alert dpr-alert--danger" style="margin-bottom:16px"><?= h($error) ?></div><?php endif; ?>
      <?php if (isset($_GET['expired'])): ?><div class="dpr-alert dpr-alert--warn" style="margin-bottom:16px">Tu sesión expiró. Ingresa de nuevo.</div><?php endif; ?>
      <a href="#" class="lp-google" onclick="alert('Inicio con Google próximamente.');return false;">
        <svg width="18" height="18" viewBox="0 0 18 18"><path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z"/><path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"/><path fill="#FBBC05" d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z"/><path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z"/></svg>
        Continuar con Google
      </a>
      <div class="lp-divider"><span>o ingresa con email</span></div>
      <form method="POST">
        <input type="hidden" name="login_submit" value="1">
        <div class="dpr-form-group" style="margin-bottom:12px">
          <label class="dpr-label">Correo electrónico</label>
          <input type="email" name="email" class="dpr-input" required autofocus value="<?= h($_POST['email'] ?? '') ?>" placeholder="usuario@email.com">
        </div>
        <div class="dpr-form-group" style="margin-bottom:8px">
          <label class="dpr-label">Contraseña</label>
          <input type="password" name="password" class="dpr-input" required placeholder="••••••••">
        </div>
        <a href="#" class="lp-forgot" onclick="document.getElementById('fp').classList.toggle('open');return false;">¿Olvidaste tu contraseña?</a>
        <div class="lp-fbox" id="fp">
          <strong style="display:block;margin-bottom:6px">Recuperar contraseña</strong>
          Escribe tu correo y te enviaremos un enlace para restablecerla.
          <div style="display:flex;gap:8px;margin-top:10px">
            <input type="email" id="fe" class="dpr-input" placeholder="tucorreo@email.com" style="flex:1">
            <button type="button" class="dpr-btn dpr-btn--primary dpr-btn--sm" onclick="sendRec()">Enviar</button>
          </div>
          <div id="fm" style="margin-top:8px;font-size:12px"></div>
        </div>
        <button type="submit" name="login_submit" class="dpr-btn dpr-btn--primary" style="width:100%;justify-content:center;padding:12px;margin-top:4px">Ingresar al sistema</button>
      </form>
      <p style="text-align:center;margin-top:14px;font-size:12px;color:var(--text-muted)">¿Eres inquilino? Tu administrador te enviará el acceso.</p>
    </div>
  </div>
</section>

<!-- CARACTERÍSTICAS -->
<section class="lp-sec" id="caracteristicas">
  <div class="lp-wrap">
    <div class="lp-label">Características</div>
    <h2 class="lp-h2">Todo lo que necesitas para administrar</h2>
    <p class="lp-sub">Desde el primer alquiler hasta un edificio completo, <?= APP_NAME ?> cubre cada aspecto del arrendamiento moderno.</p>
    <div class="lp-fg">
      <div class="lp-fc"><div class="lp-fi"><svg width="22" height="22" viewBox="0 0 22 22" fill="none"><rect x="2" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M2 9h18M8 5V3M14 5V3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div><h3>Pagos parciales y abonos</h3><p>Registra abonos y pagos parciales. El sistema maneja deudas acumuladas, mora automática y saldo a favor.</p></div>
      <div class="lp-fc"><div class="lp-fi"><svg width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M11 2L3 7v13h5v-5h6v5h5V7L11 2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></div><h3>Inmuebles con fotos y video</h3><p>Carga imágenes optimizadas a WebP y embebe videos. Publica unidades disponibles en tu página pública.</p></div>
      <div class="lp-fc"><div class="lp-fi"><svg width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M4 4h14v14H4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8 4v14M4 9h14M4 14h14" stroke="currentColor" stroke-width="1.5"/></svg></div><h3>Dashboard con KPIs</h3><p>Visualiza recaudo del mes, unidades ocupadas, morosos y comprobantes por validar en tiempo real.</p></div>
      <div class="lp-fc"><div class="lp-fi"><svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M11 7v4l3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div><h3>Mora automática configurable</h3><p>Define tasas por inmueble o global. La mora se calcula y acumula automáticamente al vencer los plazos.</p></div>
      <div class="lp-fc"><div class="lp-fi"><svg width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M4 16l4-4 4 3 6-8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><rect x="2" y="2" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/></svg></div><h3>Servicios públicos</h3><p>Registra lecturas de agua, gas y energía por unidad. Calcula consumos y genera cobros por período.</p></div>
      <div class="lp-fc"><div class="lp-fi"><svg width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M16 4H6a2 2 0 0 0-2 2v12l4-2 3 2 3-2 4 2V6a2 2 0 0 0-2-2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8 9h6M8 12h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div><h3>Documentos digitales</h3><p>Contratos firmados, paz y salvos y requisitos. El inquilino accede a sus documentos desde su portal.</p></div>
    </div>
    <div style="margin-top:52px">
      <div class="lp-label">Vista del panel</div>
      <h3 style="font-size:20px;font-weight:700;color:var(--navy);margin-bottom:20px">Dashboard de administración</h3>
      <div class="lp-mock">
        <div class="lp-mhdr"><div class="lp-dot" style="background:#ff5f57"></div><div class="lp-dot" style="background:#ffbd2e"></div><div class="lp-dot" style="background:#28c841"></div><span style="color:rgba(255,255,255,.4);font-size:11px;margin-left:8px"><?= APP_NAME ?> · Dashboard</span></div>
        <div class="lp-mbody">
          <div class="lp-krow"><div class="lp-kpi lp-kpi--g"><div class="kl">Recaudado</div><div class="kv">$8.4M</div></div><div class="lp-kpi"><div class="kl">Ocupación</div><div class="kv">9/11</div></div><div class="lp-kpi lp-kpi--y"><div class="kl">Validando</div><div class="kv">2</div></div><div class="lp-kpi lp-kpi--r"><div class="kl">Morosos</div><div class="kv">1</div></div></div>
          <table class="lp-mt"><thead><tr><th>Inquilino</th><th>Unidad</th><th>Periodo</th><th>Total</th><th>Estado</th></tr></thead><tbody>
            <tr><td>Carlos Mendoza</td><td>Apto 301 · Ed. Prado</td><td>2026-04</td><td>$1.450.000</td><td><span class="lp-pp lp-pp-ok">Pagado</span></td></tr>
            <tr><td>María Torres</td><td>Apto 302 · Ed. Prado</td><td>2026-04</td><td>$1.471.750</td><td><span class="lp-pp lp-pp-w">Validando</span></td></tr>
            <tr><td>Luis Ramírez</td><td>Local 01</td><td>2026-04</td><td>$2.100.000</td><td><span class="lp-pp lp-pp-d">Moroso</span></td></tr>
          </tbody></table>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- PRECIOS -->
<section class="lp-sec lp-sec--alt" id="precios">
  <div class="lp-wrap">
    <div class="lp-label">Planes</div>
    <h2 class="lp-h2">Precios transparentes, sin sorpresas</h2>
    <p class="lp-sub">El precio varía según número de unidades y usuarios. Todas las funciones disponibles desde el plan gratuito.</p>
    <div class="lp-pg">
      <div class="lp-pc"><div class="lp-pname">Gratuito</div><div class="lp-pamt">$0 <span>/ mes</span></div><p class="lp-pdesc">Para propietarios que comienzan con su primera unidad.</p><ul class="lp-plist"><li>1 unidad arrendable</li><li>1 administrador</li><li>Pagos parciales y mora</li><li>Servicios públicos</li><li>Portal del inquilino</li><li>Documentos digitales</li><li>Reportes mensuales</li></ul><a href="#contacto" class="lp-pbtn lp-pbtn--o">Comenzar gratis</a></div>
      <div class="lp-pc lp-pc--feat"><div class="lp-pbadge">Más popular</div><div class="lp-pname">Pro</div><div class="lp-pamt">$49K <span>/ mes</span></div><p class="lp-pdesc">Para propietarios con varias unidades y varios usuarios.</p><ul class="lp-plist"><li>Hasta 20 unidades</li><li>3 administradores</li><li>Todo del plan Gratuito</li><li>Galería fotos y video embed</li><li>Página de disponibles pública</li><li>Alertas por correo</li><li>Auditoría completa</li></ul><a href="#contacto" class="lp-pbtn lp-pbtn--s">Contratar Pro</a></div>
      <div class="lp-pc"><div class="lp-pname">Premium</div><div class="lp-pamt">$149K <span>/ mes</span></div><p class="lp-pdesc">Con hosting y dominio exclusivo para máxima seguridad de tu información.</p><ul class="lp-plist"><li>Unidades ilimitadas</li><li>Usuarios ilimitados</li><li>Todo del plan Pro</li><li><strong>Hosting dedicado exclusivo</strong></li><li><strong>Dominio propio incluido</strong></li><li><strong>Backups diarios automáticos</strong></li><li>Soporte prioritario</li></ul><a href="#contacto" class="lp-pbtn lp-pbtn--o">Contratar Premium</a></div>
    </div>
  </div>
</section>

<!-- TESTIMONIOS -->
<section class="lp-sec" id="testimonios">
  <div class="lp-wrap">
    <div class="lp-label">Testimonios</div>
    <h2 class="lp-h2">Lo que dicen nuestros clientes</h2>
    <p class="lp-sub">Propietarios e inmobiliarias ya confían en <?= APP_NAME ?> para gestionar sus arrendamientos.</p>
    <div class="lp-ts"><div class="lp-tt" id="ttrack">
      <?php
      $ts=[
        ['CM','Carlos Martínez','Propietario · 8 apartamentos',5,'Antes llevaba todo en Excel y era un caos. Ahora los inquilinos suben sus comprobantes solos y yo solo valido. Me ahorra horas cada mes.'],
        ['LP','Laura Pedraza','Administradora · Centro comercial',5,'La mora automática fue un game changer. El sistema calcula todo solo y yo solo reviso el reporte.'],
        ['JR','Jorge Restrepo','Propietario · 15 unidades',4,'Tengo el plan Premium con dominio propio y estoy muy tranquilo con la seguridad de mi información.'],
        ['MV','María Velásquez','Propietaria · 3 casas',5,'Los pagos parciales me salvaron. Tenía inquilinos que hacían abonos y no sabía cómo cuadrar.'],
        ['AH','Andrés Herrera','Propietario · Edificio 12 unidades',5,'Mis inquilinos ven sus estados de cuenta y suben comprobantes solos. Ya no me llaman para preguntar cuánto deben.'],
      ];
      foreach($ts as $t):
        $stars=str_repeat('★',$t[3]).str_repeat('☆',5-$t[3]);
      ?>
      <div class="lp-tc"><div class="lp-tstars"><?= $stars ?></div><p class="lp-ttext">"<?= h($t[4]) ?>"</p><div class="lp-tau"><div class="lp-tav"><?= h($t[0]) ?></div><div><div class="lp-tn"><?= h($t[1]) ?></div><div class="lp-tr"><?= h($t[2]) ?></div></div></div></div>
      <?php endforeach; ?>
    </div></div>
    <div class="lp-dots" id="tdots"></div>
    <div style="text-align:center"><a href="<?= BASE_URL ?>/unidades.php" class="lp-avail">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 14V5L8 1l6 4v9H11v-5H5v5H2Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
      Ver unidades disponibles
    </a></div>
  </div>
</section>

<!-- CONTACTO -->
<section class="lp-sec lp-sec--alt" id="contacto">
  <div class="lp-wrap">
    <div class="lp-label">Contacto</div>
    <h2 class="lp-h2">¿Listo para empezar?</h2>
    <p class="lp-sub">Escríbenos y te ayudamos a configurar tu cuenta en minutos.</p>
    <div class="lp-cg">
      <div class="lp-ci">
        <h3>Información de contacto</h3>
        <p>Disponibles para ayudarte a migrar tu información y configurar el sistema.</p>
        <div class="lp-cd"><div class="lp-cd-ico"><svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M3 4h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.3"/><path d="M2 5l7 5 7-5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></div><div class="lp-cd-txt"><strong>Correo</strong>info@propertyrent.com.co</div></div>
        <div class="lp-cd"><div class="lp-cd-ico"><svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M3 3h3l1.5 3.75L6 8.25s1.125 2.25 3.75 3.75l1.5-1.5L15 12v3s-6.75 1.5-12-9z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg></div><div class="lp-cd-txt"><strong>WhatsApp</strong>+57 300 000 0000</div></div>
        <div class="lp-cd"><div class="lp-cd-ico"><svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.3"/><path d="M9 1a7 7 0 0 1 7 7c0 5-7 10-7 10S2 13 2 8a7 7 0 0 1 7-7z" stroke="currentColor" stroke-width="1.3"/></svg></div><div class="lp-cd-txt"><strong>Horario</strong>Lun – Vie · 8am – 6pm Colombia</div></div>
      </div>
      <div class="lp-cf">
        <form method="POST" action="">
          <div class="dpr-form-grid">
            <div class="dpr-form-group"><label class="dpr-label">Nombre completo</label><input type="text" name="c_nombre" class="dpr-input" placeholder="Tu nombre"></div>
            <div class="dpr-form-group"><label class="dpr-label">Correo electrónico</label><input type="email" name="c_email" class="dpr-input" placeholder="tu@email.com"></div>
            <div class="dpr-form-group"><label class="dpr-label">Teléfono / WhatsApp</label><input type="text" name="c_tel" class="dpr-input" placeholder="+57 300..."></div>
            <div class="dpr-form-group"><label class="dpr-label">¿Cuántas unidades?</label><select name="c_unidades" class="dpr-select"><option value="">Seleccionar...</option><option>1 unidad</option><option>2 – 5 unidades</option><option>6 – 20 unidades</option><option>Más de 20</option></select></div>
            <div class="dpr-form-group dpr-form-group--full"><label class="dpr-label">Mensaje</label><textarea name="c_msg" class="dpr-textarea" style="height:80px" placeholder="¿En qué te podemos ayudar?"></textarea></div>
          </div>
          <div style="margin-top:16px"><button type="submit" name="c_submit" class="dpr-btn dpr-btn--primary" style="width:100%;justify-content:center;padding:13px">Enviar mensaje</button></div>
        </form>
      </div>
    </div>
  </div>
</section>

<footer class="lp-foot">
  <div class="lp-foot-in">
    <div>© <?= date('Y') ?> <?= APP_NAME ?>. Todos los derechos reservados.</div>
    <div style="display:flex;gap:18px;flex-wrap:wrap;justify-content:center">
      <a href="#inicio">Inicio</a><a href="#caracteristicas">Características</a>
      <a href="#precios">Precios</a><a href="<?= BASE_URL ?>/unidades.php">Unidades</a><a href="#contacto">Contacto</a>
    </div>
  </div>
</footer>

<a href="https://wa.me/573000000000?text=Hola%2C+me+interesa+<?= rawurlencode(APP_NAME) ?>" target="_blank" class="lp-wa">
  <span class="lp-wa-tip">¡Escríbenos!</span>
  <svg width="28" height="28" viewBox="0 0 28 28" fill="none"><path d="M14 2C7.373 2 2 7.373 2 14c0 2.124.553 4.12 1.522 5.851L2 26l6.355-1.496A11.94 11.94 0 0 0 14 26c6.627 0 12-5.373 12-12S20.627 2 14 2z" fill="white"/><path d="M19.823 17.265c-.262-.131-1.55-.763-1.79-.85-.239-.088-.413-.131-.587.132-.174.262-.674.85-.826 1.024-.152.175-.304.197-.567.066-.262-.131-1.107-.407-2.108-1.298-.779-.694-1.305-1.55-1.458-1.812-.152-.262-.016-.404.115-.534.117-.117.262-.305.393-.457.131-.153.174-.262.262-.436.087-.175.043-.328-.022-.46-.066-.13-.587-1.414-.804-1.937-.212-.508-.427-.44-.587-.447-.152-.007-.327-.009-.502-.009s-.458.066-.698.328c-.24.262-.915.895-.915 2.18s.937 2.528 1.068 2.702c.13.175 1.844 2.815 4.468 3.948.624.27 1.11.43 1.49.55.626.2 1.195.171 1.644.104.501-.075 1.55-.633 1.768-1.245.218-.612.218-1.137.152-1.245-.065-.109-.239-.175-.502-.306z" fill="#25D366"/></svg>
</a>

<script>
document.querySelectorAll('a[href^="#"]').forEach(function(a){a.addEventListener('click',function(e){var t=document.querySelector(a.getAttribute('href'));if(t){e.preventDefault();t.scrollIntoView({behavior:'smooth',block:'start'})}})});
function sendRec(){var e=document.getElementById('fe').value.trim(),m=document.getElementById('fm');if(!e){m.innerHTML='<span style="color:#dc2626">Ingresa tu correo.</span>';return}m.innerHTML='<span style="color:#065f46">Si el correo está registrado, recibirás un enlace en minutos.</span>'}
(function(){
  var track=document.getElementById('ttrack'),dotsEl=document.getElementById('tdots');
  var cards=track.querySelectorAll('.lp-tc'),total=cards.length,cur=0,timer;
  for(var i=0;i<total;i++){(function(i){var d=document.createElement('button');d.className='lp-db'+(i===0?' on':'');d.onclick=function(){clearTimeout(timer);goTo(i);auto()};dotsEl.appendChild(d)})(i)}
  function goTo(idx){cur=(idx+total)%total;var w=cards[0].offsetWidth+22;track.style.transform='translateX(-'+cur*w+'px)';dotsEl.querySelectorAll('.lp-db').forEach(function(d,i){d.classList.toggle('on',i===cur)})}
  function auto(){timer=setTimeout(function(){goTo(cur+1);auto()},4500)}
  auto();window.addEventListener('resize',function(){goTo(cur)});
})();
</script>
</body>
</html>
