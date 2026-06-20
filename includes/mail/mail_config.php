<?php
// =============================================================
//  PropertyRent — Configuración de correo SMTP
//
//  OPCIÓN A (recomendada): instala PHPMailer vía Composer:
//    composer require phpmailer/phpmailer
//  Y descomenta el bloque PHPMailer más abajo.
//
//  OPCIÓN B (sin Composer): descarga los 3 archivos de
//    https://github.com/PHPMailer/PHPMailer/tree/master/src
//    y colócalos en: includes/mail/PHPMailer/
//
//  OPCIÓN C: usa mail() nativo de PHP (sin TLS/autenticación).
//    Funciona en hosting compartido con sendmail configurado.
// =============================================================

// ---- Credenciales SMTP ----
define('MAIL_HOST',       'smtp.gmail.com');     // Gmail, o tu SMTP
define('MAIL_PORT',       587);                  // 587=TLS, 465=SSL
define('MAIL_SECURE',     'tls');                // 'tls' o 'ssl'
define('MAIL_USERNAME',   'tu@gmail.com');       // Tu cuenta SMTP
define('MAIL_PASSWORD',   'xxxx xxxx xxxx xxxx'); // Contraseña de aplicación Gmail
define('MAIL_FROM_EMAIL', 'noreply@tudominio.com');
define('MAIL_FROM_NAME',  APP_NAME);
define('MAIL_REPLY_TO',   'admin@tudominio.com');

// Modo debug: 0=off, 2=verbose en error_log
define('MAIL_DEBUG',      0);

// ¿Dónde está PHPMailer? (Opción B: descarga manual)
define('PHPMAILER_PATH',  __DIR__ . '/PHPMailer/');
