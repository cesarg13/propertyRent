<?php
// =============================================================
//  PropertyRent — Conexión a base de datos (mysqli)
// =============================================================

define('DB_HOST',    'localhost');
define('DB_USER', 'username');          // Cambiar en producción
define('DB_PASS', 'password');              // Cambiar en producción
define('DB_NAME', 'database');
define('DB_CHARSET', 'utf8mb4');

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($mysqli->connect_errno) {
    http_response_code(500);
    die(json_encode(['error' => 'Error de conexión: ' . $mysqli->connect_error]));
}

$mysqli->set_charset(DB_CHARSET);
$mysqli->query("SET time_zone = '-05:00'");

function _dpr_infer_types(array $params): string {
    return implode('', array_map(function($v) {
        if (is_int($v))   return 'i';
        if (is_float($v)) return 'd';
        return 's'; // string, null, bool, enum — todo como s
    }, $params));
}

function db_query(mysqli $db, string $sql, string $types = '', array $params = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) { error_log("DB prepare error: " . $db->error . " | SQL: $sql"); return []; }
    if ($params) {
        $t = $types ?: _dpr_infer_types($params);
        $stmt->bind_param($t, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) return [];
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function db_execute(mysqli $db, string $sql, string $types = '', array $params = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) { error_log("DB prepare error: " . $db->error . " | SQL: $sql"); return ['success'=>false,'error'=>$db->error]; }
    if ($params) {
        $t = $types ?: _dpr_infer_types($params);
        $stmt->bind_param($t, ...$params);
    }
    $ok = $stmt->execute();
    $r  = ['success'=>$ok,'insert_id'=>$stmt->insert_id,'affected_rows'=>$stmt->affected_rows,'error'=>$ok?null:$stmt->error];
    $stmt->close();
    return $r;
}

// Stubs de notificaciones — el envío real requiere configurar SMTP en mail_config.php
// Para activar emails: reemplaza estos stubs con require_once de notifications.php
if (!function_exists('dpr_notify_payment_approved')) {
    function dpr_notify_payment_approved(mysqli $db, int $payment_id): void {}
    function dpr_notify_payment_rejected(mysqli $db, int $payment_id, string $motivo = ''): void {}
    function dpr_notify_new_ticket(mysqli $db, int $ticket_id): void {}
    function dpr_notify_mora(mysqli $db, int $lease_id): void {}
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function fmt_money(float $amount): string {
    return '$' . number_format($amount, 0, ',', '.');
}

function fmt_date(?string $date): string {
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') return '—';
    return date('d M Y', strtotime($date));
}
