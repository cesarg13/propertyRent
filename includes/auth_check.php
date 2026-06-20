<?php
// =============================================================
//  DemoPropertyRent — Verificación de autenticación y roles
//  Uso: require_once '../includes/demoPropertyRent_auth_check.php';
//  Para restringir a admin: dpr_require_role(ROL_ADMIN);
// =============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function dpr_is_logged_in(): bool {
    return isset($_SESSION['dpr_user_id'], $_SESSION['dpr_rol']);
}

function dpr_require_login(): void {
    if (!dpr_is_logged_in()) {
        header('Location: ' . BASE_URL . '/index.php?expired=1');
        exit;
    }
    // Timeout de sesión
    if (isset($_SESSION['dpr_last_activity']) &&
        (time() - $_SESSION['dpr_last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . '/index.php?expired=1');
        exit;
    }
    $_SESSION['dpr_last_activity'] = time();
}

function dpr_require_role(string $role): void {
    dpr_require_login();
    if ($_SESSION['dpr_rol'] !== $role) {
        http_response_code(403);
        die('<h2>Acceso denegado. No tienes permiso para ver esta página.</h2>');
    }
}

function dpr_current_user(): array {
    return [
        'id'     => $_SESSION['dpr_user_id']   ?? 0,
        'nombre' => $_SESSION['dpr_nombre']     ?? '',
        'rol'    => $_SESSION['dpr_rol']        ?? '',
        'email'  => $_SESSION['dpr_email']      ?? '',
    ];
}
