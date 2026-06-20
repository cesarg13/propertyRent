<?php
// =============================================================
//  PropertyRent — Configuración global
// =============================================================

define('APP_NAME',    'PropertyRent');
define('APP_VERSION', '1.0.0');

// ⚠️  Sin slash al final
define('BASE_URL',    'https://sandbox.smartgigas.com/propertyRent');
define('BASE_PATH',   dirname(__DIR__));

define('UPLOAD_PATH', BASE_PATH . '/uploads/');
define('UPLOAD_URL',  BASE_URL  . '/uploads/');   // 

// Tipos MIME permitidos para comprobantes/documentos
define('ALLOWED_MIME', ['image/jpeg','image/png','image/webp','image/gif','application/pdf']);
define('MAX_UPLOAD_MB', 5);

// Sesión
define('SESSION_TIMEOUT', 3600); // 1 hora

// Roles
define('ROL_ADMIN',  'admin');
define('ROL_TENANT', 'tenant');

// Timezone Colombia
date_default_timezone_set('America/Bogota');

// Producción: errores ocultos al usuario, guardados en log del servidor
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
