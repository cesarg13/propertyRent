<?php
// =============================================================
//  PropertyRent — API: Subir comprobante de pago (AJAX)
//  POST /api/upload_api.php
//  Content-Type: multipart/form-data
//  Params: payment_id (int), comprobante (file), nota_inquilino (string)
//  Returns: JSON { success, message, comprobante_url?, payment_id? }
//  Acceso: admin Y tenant (cada uno verifica ownership)
// =============================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

dpr_require_login();
$user = dpr_current_user();

$payment_id    = (int)($_POST['payment_id']    ?? 0);
$nota          = trim($_POST['nota_inquilino'] ?? '');
$force_approve = isset($_POST['force_approve']) && $user['rol'] === ROL_ADMIN;

if (!$payment_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'payment_id requerido.']);
    exit;
}

// Verificar que el pago existe y pertenece al usuario (o es admin)
if ($user['rol'] === ROL_ADMIN) {
    $pay = db_query($mysqli,
        "SELECT p.*, l.tenant_id FROM payments p JOIN leases l ON l.id=p.lease_id WHERE p.id=?",
        'i', [$payment_id]);
} else {
    $pay = db_query($mysqli,
        "SELECT p.*, l.tenant_id FROM payments p
         JOIN leases l ON l.id=p.lease_id
         WHERE p.id=? AND l.tenant_id=?",
        'ii', [$payment_id, $user['id']]);
}

if (!$pay) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Pago no encontrado o sin permisos.']);
    exit;
}
$pay = $pay[0];

// Inquilinos no pueden subir si ya está pagado/validando/condonado
if ($user['rol'] === ROL_TENANT && in_array($pay['estado'], ['pagado','validando','condonado'])) {
    echo json_encode(['success' => false, 'message' => 'Este pago no acepta comprobantes en su estado actual.']);
    exit;
}

if (empty($_FILES['comprobante']['name'])) {
    echo json_encode(['success' => false, 'message' => 'Debes adjuntar un archivo.']);
    exit;
}

// Subir archivo
$up = dpr_upload_image($_FILES['comprobante'], 'comprobantes');
if (!$up['success']) {
    echo json_encode(['success' => false, 'message' => $up['error']]);
    exit;
}

// Si el admin sube y force_approve → marcar directo como pagado
if ($force_approve) {
    $metodo = $_POST['metodo'] ?? 'otro';
    db_execute($mysqli,
        "UPDATE payments SET
            estado='pagado', comprobante_url=?, nota_admin=?,
            metodo=?, validado_por=?, fecha_validacion=NOW(),
            fecha_pago=NOW(), updated_at=NOW()
         WHERE id=?",
        'sssii', [$up['url'], $nota, $metodo, $user['id'], $payment_id]);
    dpr_audit_log($mysqli, $user['id'], 'REGISTER_PAYMENT', 'payments', $payment_id,
        "Admin aprobó pago directo: {$up['filename']}");

    // Notificar al inquilino
    dpr_notify_payment_approved($mysqli, $payment_id);

} else {
    // Inquilino o admin sin force → pasa a validando
    db_execute($mysqli,
        "UPDATE payments SET
            estado='validando', comprobante_url=?,
            nota_inquilino=?, updated_at=NOW()
         WHERE id=?",
        'ssi', [$up['url'], $nota, $payment_id]);
    dpr_audit_log($mysqli, $user['id'], 'UPLOAD', 'payments', $payment_id,
        "Subió comprobante: {$up['filename']}");

    // Notificar al admin
    dpr_notify_admin_new_comprobante($mysqli, $payment_id);
}

echo json_encode([
    'success'          => true,
    'message'          => $force_approve
        ? 'Pago registrado y aprobado.'
        : 'Comprobante enviado. El administrador lo revisará pronto.',
    'comprobante_url'  => $up['url'],
    'payment_id'       => $payment_id,
    'nuevo_estado'     => $force_approve ? 'pagado' : 'validando',
]);
