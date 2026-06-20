<?php
// =============================================================
//  PropertyRent — API: Medidores (AJAX)
//  GET  ?action=last_reading&unit_id=X&tipo=agua  → última lectura
//  POST action=save_reading                        → guardar lectura
//  POST action=preview                             → calcular sin guardar
//  Acceso: solo admin
// =============================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

dpr_require_role(ROL_ADMIN);
$user   = dpr_current_user();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ---------------------------------------------------------------
// GET: última lectura de una unidad/tipo
// ---------------------------------------------------------------
if ($method === 'GET' && $action === 'last_reading') {
    $unit_id = (int)($_GET['unit_id'] ?? 0);
    $tipo    = $_GET['tipo'] ?? 'agua';
    if (!$unit_id) { echo json_encode(['success'=>false,'message'=>'unit_id requerido']); exit; }

    $rows = db_query($mysqli,
        "SELECT lectura_actual, periodo FROM meter_readings
         WHERE unit_id=? AND tipo=? ORDER BY periodo DESC LIMIT 1",
        'is', [$unit_id, $tipo]);

    echo json_encode([
        'success'         => true,
        'lectura_anterior'=> $rows ? (float)$rows[0]['lectura_actual'] : 0,
        'ultimo_periodo'  => $rows ? $rows[0]['periodo'] : null,
    ]);
    exit;
}

// ---------------------------------------------------------------
// POST: calcular preview sin guardar
// ---------------------------------------------------------------
if ($method === 'POST' && $action === 'preview') {
    $lec_ant    = (float)($_POST['lectura_anterior'] ?? 0);
    $lec_act    = (float)($_POST['lectura_actual']   ?? 0);
    $tarifa     = (float)($_POST['tarifa']            ?? 0);
    $cargo_fijo = (float)($_POST['cargo_fijo']        ?? 0);
    $calc = dpr_calcular_consumo($lec_ant, $lec_act, $tarifa, $cargo_fijo);
    echo json_encode(['success' => true] + $calc);
    exit;
}

// ---------------------------------------------------------------
// POST: guardar lectura
// ---------------------------------------------------------------
if ($method === 'POST' && $action === 'save_reading') {
    $unit_id    = (int)($_POST['unit_id']    ?? 0);
    $lease_id   = (int)($_POST['lease_id']   ?? 0);
    $tipo       = $_POST['tipo']              ?? 'agua';
    $periodo    = $_POST['periodo']           ?? date('Y-m');
    $lec_ant    = (float)($_POST['lectura_anterior'] ?? 0);
    $lec_act    = (float)($_POST['lectura_actual']   ?? 0);
    $tarifa     = (float)($_POST['tarifa']            ?? 0);
    $cargo_fijo = (float)($_POST['cargo_fijo']        ?? 0);
    $incluido   = isset($_POST['incluido']) ? 1 : 0;

    if (!$unit_id || !$lease_id || $tarifa < 0) {
        echo json_encode(['success' => false, 'message' => 'Parámetros incompletos.']); exit;
    }

    $calc  = dpr_calcular_consumo($lec_ant, $lec_act, $tarifa, $cargo_fijo);
    $valor = $incluido ? 0 : $calc['valor'];

    db_execute($mysqli,
        "INSERT INTO meter_readings
            (unit_id, tipo, periodo, lectura_anterior, lectura_actual, consumo, tarifa, cargo_fijo, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            lectura_actual=VALUES(lectura_actual), consumo=VALUES(consumo),
            tarifa=VALUES(tarifa), cargo_fijo=VALUES(cargo_fijo)",
        'issdddddi', [$unit_id,$tipo,$periodo,$lec_ant,$lec_act,$calc['consumo'],$tarifa,$cargo_fijo,$user['id']]);

    db_execute($mysqli,
        "INSERT INTO public_services (unit_id,lease_id,tipo,periodo,valor,incluido,estado)
         VALUES (?,?,?,?,?,?,'pendiente')
         ON DUPLICATE KEY UPDATE valor=VALUES(valor), incluido=VALUES(incluido)",
        'iissdi', [$unit_id,$lease_id,$tipo,$periodo,$valor,$incluido]);

    dpr_audit_log($mysqli, $user['id'], 'INSERT', 'meter_readings', null,
        "Lectura $tipo unidad=$unit_id periodo=$periodo consumo={$calc['consumo']}");

    echo json_encode([
        'success' => true,
        'message' => "Lectura guardada. Consumo: {$calc['consumo']} · Cobro: $" . number_format($valor, 0, ',', '.'),
        'consumo' => $calc['consumo'],
        'valor'   => $valor,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Acción no reconocida.']);
