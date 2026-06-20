<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user  = dpr_current_user();
$lease = db_query($mysqli,
    "SELECT l.id, l.unit_id FROM leases l
     WHERE l.tenant_id = ? AND l.estado = 'activo' LIMIT 1",
    'i', [$user['id']]);
$lease = $lease[0] ?? null;

$tbl = $mysqli->query("SHOW TABLES LIKE 'support_ticket_files'");
$has_files_table = $tbl && $tbl->num_rows > 0;

$col_info  = $mysqli->query("SHOW COLUMNS FROM support_tickets LIKE 'categoria'");
$col_row   = $col_info ? $col_info->fetch_assoc() : null;
$has_seguridad = $col_row && strpos($col_row['Type'] ?? '', 'seguridad') !== false;

$tickets = db_query($mysqli,
    "SELECT * FROM support_tickets WHERE tenant_id = ? ORDER BY created_at DESC",
    'i', [$user['id']]);

$archivos_map = [];
if ($has_files_table && $tickets) {
    $ids = implode(',', array_map('intval', array_column($tickets, 'id')));
    if ($ids) {
        $files_rows = db_query($mysqli,
            "SELECT * FROM support_ticket_files WHERE ticket_id IN ($ids)");
        foreach (($files_rows ?: []) as $f) {
            $archivos_map[(int)$f['ticket_id']][] = $f;
        }
    }
}

echo "<pre>Datos OK. Intentando cargar header...\n";
flush();

$page_title  = 'Soporte';
$active_menu = 'support';

// Intentar include del header y capturar error
try {
    ob_start();
    require_once __DIR__ . '/../includes/header.php';
    $out = ob_get_clean();
    echo "Header OK (" . strlen($out) . " bytes)\n";
    echo $out;
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR EN HEADER: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . " línea " . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
echo "</pre>";
