<?php
// =============================================================
//  PropertyRent — API: Validar o rechazar pago (AJAX)
//  POST /api/payments_api.php
//  Params: action (validate|reject|recalc_mora), payment_id, nota_admin?
//  Returns: JSON { success, message, nuevo_estado?, valor_mora? }
//  Acceso: solo admin (excepto recalc_mora que puede ser llamado por cron)
// =============================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

dpr_require_login();
$user = dpr_current_user();

$action     = $_POST['action']     ?? '';
$payment_id = (int)($_POST['payment_id'] ?? 0);
$nota       = trim($_POST['nota_admin']  ?? '');

if (!$payment_id || !$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parámetros requeridos: action, payment_id.']);
    exit;
}

// recalc_mora no requiere admin (cron lo llama)
if ($action !== 'recalc_mora' && $user['rol'] !== ROL_ADMIN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
    exit;
}

// Obtener el pago
$pay = db_query($mysqli,
    "SELECT p.*, l.tenant_id, l.valor_mensual FROM payments p
     JOIN leases l ON l.id=p.lease_id WHERE p.id=?",
    'i', [$payment_id]);

if (!$pay) {
    echo json_encode(['success' => false, 'message' => 'Pago no encontrado.']);
    exit;
}
$pay = $pay[0];

// ---------------------------------------------------------------
switch ($action) {

    case 'validate':
        if ($pay['estado'] !== 'validando') {
            echo json_encode(['success' => false, 'message' => 'El pago no está en estado "validando".',
                'estado_actual' => $pay['estado']]);
            exit;
        }
        db_execute($mysqli,
            "UPDATE payments SET estado='pagado', nota_admin=?,
             validado_por=?, fecha_validacion=NOW(), fecha_pago=COALESCE(fecha_pago, NOW()),
             updated_at=NOW() WHERE id=?",
            'sii', [$nota, $user['id'], $payment_id]);
        dpr_audit_log($mysqli, $user['id'], 'VALIDATE', 'payments', $payment_id, "Aprobó pago: $nota");
        dpr_notify_payment_approved($mysqli, $payment_id);
        echo json_encode(['success' => true, 'message' => 'Pago aprobado.', 'nuevo_estado' => 'pagado']);
        break;

    case 'reject':
        if (!in_array($pay['estado'], ['validando','pendiente'])) {
            echo json_encode(['success' => false, 'message' => 'No se puede rechazar en estado: '.$pay['estado']]);
            exit;
        }
        db_execute($mysqli,
            "UPDATE payments SET estado='pendiente', comprobante_url=NULL,
             nota_admin=?, updated_at=NOW() WHERE id=?",
            'si', [$nota ?: 'Comprobante rechazado. Por favor sube un comprobante válido.', $payment_id]);
        dpr_audit_log($mysqli, $user['id'], 'REJECT', 'payments', $payment_id, "Rechazó: $nota");
        dpr_notify_payment_rejected($mysqli, $payment_id, $nota);
        echo json_encode(['success' => true, 'message' => 'Comprobante rechazado. Inquilino notificado.',
            'nuevo_estado' => 'pendiente']);
        break;

    case 'recalc_mora':
        // Recalcula mora actualizada para un pago específico y retorna el valor
        $mora = dpr_calcular_mora($mysqli, $pay['lease_id'],
            (float)$pay['valor_arriendo'], $pay['fecha_vencimiento']);
        if ($mora['valor_mora'] > 0) {
            db_execute($mysqli,
                "UPDATE payments SET estado='moroso', valor_mora=?, valor_total=?, updated_at=NOW() WHERE id=?",
                'ddi', [$mora['valor_mora'], $pay['valor_arriendo'] + $mora['valor_mora'], $payment_id]);
        }
        echo json_encode([
            'success'    => true,
            'dias_mora'  => $mora['dias_mora'],
            'valor_mora' => $mora['valor_mora'],
            'valor_total'=> $pay['valor_arriendo'] + $mora['valor_mora'],
            'tasa'       => $mora['tasa'],
        ]);
        break;

    case 'condonar':
        // Solo admin puede condonar mora (dejar en 0)
        $motivo = trim($_POST['motivo'] ?? 'Condonación por administrador');
        db_execute($mysqli,
            "UPDATE payments SET valor_mora=0, valor_total=valor_arriendo,
             nota_admin=?, estado=CASE WHEN estado='moroso' THEN 'pendiente' ELSE estado END,
             updated_at=NOW() WHERE id=?",
            'si', [$motivo, $payment_id]);
        dpr_audit_log($mysqli, $user['id'], 'UPDATE', 'payments', $payment_id, "Condonó mora: $motivo");
        echo json_encode(['success' => true, 'message' => 'Mora condonada.']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => "Acción desconocida: $action"]);
}
