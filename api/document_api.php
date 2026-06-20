<?php
// =============================================================
//  PropertyRent — API: Subir documentos (AJAX)
//  POST /api/document_api.php
//  Params: tipo, nombre, lease_id?, tenant_id?, unit_id?, fecha_doc?, archivo (file)
//  Returns: JSON { success, message, doc_id?, archivo_url? }
//  Acceso: solo admin
// =============================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'message'=>'Método no permitido.']); exit;
}

dpr_require_role(ROL_ADMIN);
$user = dpr_current_user();

$action    = $_POST['action']    ?? 'upload';
$doc_id    = (int)($_POST['doc_id'] ?? 0);

// ---------------------------------------------------------------
// Eliminar documento
// ---------------------------------------------------------------
if ($action === 'delete' && $doc_id) {
    $doc = db_query($mysqli, "SELECT archivo_url FROM documents WHERE id=?", 'i', [$doc_id]);
    if ($doc) {
        // Eliminar archivo físico
        $path = str_replace(UPLOAD_URL, UPLOAD_PATH, $doc[0]['archivo_url']);
        if (file_exists($path)) @unlink($path);
        db_execute($mysqli, "DELETE FROM documents WHERE id=?", 'i', [$doc_id]);
        dpr_audit_log($mysqli, $user['id'], 'DELETE', 'documents', $doc_id, 'Eliminó documento');
    }
    echo json_encode(['success' => true, 'message' => 'Documento eliminado.']);
    exit;
}

// ---------------------------------------------------------------
// Subir nuevo documento
// ---------------------------------------------------------------
$tipo      = $_POST['tipo']       ?? 'otro';
$nombre    = trim($_POST['nombre'] ?? '');
$lease_id  = $_POST['lease_id']   !== '' ? (int)$_POST['lease_id']  : null;
$tenant_id = $_POST['tenant_id']  !== '' ? (int)$_POST['tenant_id'] : null;
$unit_id   = $_POST['unit_id']    !== '' ? (int)$_POST['unit_id']   : null;
$fecha_doc = $_POST['fecha_doc']  !== '' ? $_POST['fecha_doc']       : null;

if (!$nombre) {
    echo json_encode(['success' => false, 'message' => 'El nombre del documento es obligatorio.']); exit;
}
if (empty($_FILES['archivo']['name'])) {
    echo json_encode(['success' => false, 'message' => 'Debes adjuntar un archivo.']); exit;
}

$up = dpr_upload_image($_FILES['archivo'], 'documentos');
if (!$up['success']) {
    echo json_encode(['success' => false, 'message' => $up['error']]); exit;
}

$r = db_execute($mysqli,
    "INSERT INTO documents (lease_id, unit_id, tenant_id, tipo, nombre, archivo_url, fecha_doc, subido_por)
     VALUES (?,?,?,?,?,?,?,?)",
    'iiisssi', [$lease_id, $unit_id, $tenant_id, $tipo, $nombre, $up['url'], $fecha_doc, $user['id']]);

dpr_audit_log($mysqli, $user['id'], 'INSERT', 'documents', $r['insert_id'],
    "Subió documento: $nombre ($tipo)");

echo json_encode([
    'success'     => true,
    'message'     => 'Documento subido correctamente.',
    'doc_id'      => $r['insert_id'],
    'archivo_url' => $up['url'],
    'nombre'      => $nombre,
    'tipo'        => $tipo,
    'fecha_doc'   => $fecha_doc,
]);
