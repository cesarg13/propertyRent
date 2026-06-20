<?php
// =============================================================
//  PropertyRent — Diagnóstico de servidor
//  SUBIR TEMPORALMENTE a public_html/smartgigas.com/customerDevs/propertyRent/
//  Acceder: https://smartgigas.com/customerDevs/propertyRent/diagnostico.php
//  ELIMINAR después de revisar
// =============================================================

// Forzar mostrar todos los errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO PROPERTYRENT ===\n\n";

// PHP version
echo "PHP: " . PHP_VERSION . "\n";
echo "SAPI: " . PHP_SAPI . "\n\n";

// Extensiones críticas
$needed = ['mysqli', 'gd', 'fileinfo', 'json', 'mbstring', 'session'];
echo "--- Extensiones ---\n";
foreach ($needed as $ext) {
    echo $ext . ': ' . (extension_loaded($ext) ? 'OK' : 'FALTA') . "\n";
}

// WebP support en GD
echo "\n--- GD Info ---\n";
if (extension_loaded('gd')) {
    $gd = gd_info();
    echo "WebP: " . ($gd['WebP Support'] ? 'OK' : 'NO DISPONIBLE') . "\n";
    echo "JPEG: " . ($gd['JPEG Support'] ? 'OK' : 'NO') . "\n";
    echo "PNG: " . ($gd['PNG Support'] ? 'OK' : 'NO') . "\n";
} else {
    echo "GD no cargado\n";
}

// Rutas
echo "\n--- Rutas ---\n";
echo "__DIR__: " . __DIR__ . "\n";
echo "config existe: " . (file_exists(__DIR__ . '/config/config.php') ? 'SÍ' : 'NO') . "\n";
echo "config/db.php existe: " . (file_exists(__DIR__ . '/config/db.php') ? 'SÍ' : 'NO') . "\n";
echo "includes/functions.php: " . (file_exists(__DIR__ . '/includes/functions.php') ? 'SÍ' : 'NO') . "\n";

// Config
echo "\n--- Config ---\n";
if (file_exists(__DIR__ . '/config/config.php')) {
    require_once __DIR__ . '/config/config.php';
    echo "APP_NAME: " . (defined('APP_NAME') ? APP_NAME : 'NO DEFINIDO') . "\n";
    echo "BASE_URL: " . (defined('BASE_URL') ? BASE_URL : 'NO DEFINIDO') . "\n";
    echo "UPLOAD_PATH: " . (defined('UPLOAD_PATH') ? UPLOAD_PATH : 'NO DEFINIDO') . "\n";
    if (defined('UPLOAD_PATH')) {
        echo "UPLOAD_PATH existe: " . (is_dir(UPLOAD_PATH) ? 'SÍ' : 'NO - CREAR') . "\n";
        echo "UPLOAD_PATH writable: " . (is_writable(UPLOAD_PATH) ? 'SÍ' : 'NO - CHMOD 755') . "\n";
    }
}

// DB
echo "\n--- Base de datos ---\n";
if (file_exists(__DIR__ . '/config/db.php')) {
    try {
        require_once __DIR__ . '/config/db.php';
        echo "Conexión: OK\n";
        // Check tables
        $tables = ['users','properties','units','leases','payments','public_services','support_tickets'];
        foreach ($tables as $t) {
            $r = $mysqli->query("SHOW TABLES LIKE '$t'");
            echo "Tabla $t: " . ($r && $r->num_rows > 0 ? 'OK' : 'FALTA') . "\n";
        }
        // Check migration columns
        $r = $mysqli->query("SHOW COLUMNS FROM payments LIKE 'monto_pagado'");
        echo "Migración parciales: " . ($r && $r->num_rows > 0 ? 'APLICADA' : 'PENDIENTE (normal si no la corriste)') . "\n";
        $r2 = $mysqli->query("SHOW COLUMNS FROM units LIKE 'publicar'");
        echo "Migración units.publicar: " . ($r2 && $r2->num_rows > 0 ? 'APLICADA' : 'PENDIENTE') . "\n";
    } catch (Exception $e) {
        echo "ERROR de BD: " . $e->getMessage() . "\n";
    }
} else {
    echo "config/db.php no encontrado\n";
}

// session
echo "\n--- Sesión ---\n";
if (session_status() === PHP_SESSION_NONE) session_start();
echo "Session ID: " . session_id() . "\n";
echo "save_path: " . session_save_path() . "\n";
$sp = session_save_path() ?: sys_get_temp_dir();
echo "save_path writable: " . (is_writable($sp) ? 'SÍ' : 'NO') . "\n";

// .htaccess
echo "\n--- .htaccess ---\n";
echo ".htaccess existe: " . (file_exists(__DIR__ . '/.htaccess') ? 'SÍ' : 'NO') . "\n";
echo "tenant/.htaccess: " . (file_exists(__DIR__ . '/tenant/.htaccess') ? 'SÍ' : 'NO') . "\n";

// Permisos tenant/
echo "\n--- Archivos tenant/ ---\n";
$tenant_files = ['dashboard.php','payments.php','services.php','support.php'];
foreach ($tenant_files as $f) {
    $path = __DIR__ . '/tenant/' . $f;
    if (file_exists($path)) {
        echo "$f: OK (" . filesize($path) . " bytes)\n";
    } else {
        echo "$f: NO ENCONTRADO\n";
    }
}

// Probar include de auth_check
echo "\n--- Includes críticos ---\n";
$inc_files = ['includes/auth_check.php','includes/functions.php','includes/header.php'];
foreach ($inc_files as $f) {
    echo "$f: " . (file_exists(__DIR__ . "/$f") ? 'OK (' . filesize(__DIR__ . "/$f") . ' bytes)' : 'NO ENCONTRADO') . "\n";
}

// ROL constants
echo "\n--- Constantes de rol ---\n";
echo "ROL_ADMIN: " . (defined('ROL_ADMIN') ? ROL_ADMIN : 'NO DEFINIDA') . "\n";
echo "ROL_TENANT: " . (defined('ROL_TENANT') ? ROL_TENANT : 'NO DEFINIDA') . "\n";

// Intentar incluir header para ver si explota
echo "\n--- Test de functions.php ---\n";
if (file_exists(__DIR__ . '/includes/functions.php')) {
    try {
        // No incluir de nuevo si ya está incluido por config
        if (!function_exists('dpr_current_user')) {
            require_once __DIR__ . '/includes/functions.php';
        }
        echo "functions.php: OK\n";
        echo "fmt_money existe: " . (function_exists('fmt_money') ? 'SÍ' : 'NO') . "\n";
        echo "dpr_upload_file existe: " . (function_exists('dpr_upload_file') ? 'SÍ' : 'NO') . "\n";
        echo "dpr_upload_image existe: " . (function_exists('dpr_upload_image') ? 'SÍ' : 'NO') . "\n";
        echo "dpr_registrar_abono existe: " . (function_exists('dpr_registrar_abono') ? 'SÍ' : 'NO') . "\n";
    } catch (Throwable $e) {
        echo "ERROR en functions.php: " . $e->getMessage() . " en línea " . $e->getLine() . "\n";
    }
}

echo "\n=== FIN DIAGNÓSTICO ===\n";
echo "Elimina este archivo después de revisar.\n";
