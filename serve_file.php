<?php
// =============================================================
//  PropertyRent — Servidor seguro de archivos privados
//  Sirve archivos de /uploads/ solo a usuarios autenticados
//  Uso: serve_file.php?f=comprobantes/archivo.webp
// =============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth_check.php';
dpr_require_login();

$requested = $_GET['f'] ?? '';
// Sanitizar: eliminar ../ y caracteres peligrosos
$requested = str_replace(['..', '\\', chr(0)], '', $requested);
$requested = ltrim($requested, '/');

if (!$requested) { http_response_code(400); exit('Archivo no especificado'); }

$path = UPLOAD_PATH . $requested;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404); exit('Archivo no encontrado');
}

// Verificar que esté dentro del directorio de uploads
$real    = realpath($path);
$base    = realpath(UPLOAD_PATH);
if (!$real || strpos($real, $base) !== 0) {
    http_response_code(403); exit('Acceso denegado');
}

// Si es imagen de unidad/propiedad pública, permitir sin auth
// (solo comprobantes y documentos requieren login)
$subfolder = explode('/', $requested)[0] ?? '';
$public_folders = ['unidades', 'fotos_propiedades'];
if (!in_array($subfolder, $public_folders)) {
    // Requiere estar logueado (ya verificado arriba)
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($real);
$allowed_serve = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
if (!in_array($mime, $allowed_serve)) {
    http_response_code(403); exit('Tipo de archivo no permitido');
}

// Cabeceras
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($real) . '"');
header('Cache-Control: private, max-age=3600');
readfile($real);
exit;
