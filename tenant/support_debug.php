<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_TENANT);

$user = dpr_current_user();

// Test cada paso y mostrar dónde falla
echo "<pre>";
echo "1. Auth OK - user: " . $user['id'] . "\n";

$lease = db_query($mysqli,
    "SELECT l.id, l.unit_id FROM leases l
     WHERE l.tenant_id = ? AND l.estado = 'activo' LIMIT 1",
    'i', [$user['id']]);
$lease = $lease[0] ?? null;
echo "2. Lease: " . ($lease ? "id={$lease['id']}" : "null") . "\n";

$tbl = $mysqli->query("SHOW TABLES LIKE 'support_ticket_files'");
$has_files_table = $tbl && $tbl->num_rows > 0;
echo "3. support_ticket_files tabla: " . ($has_files_table ? "EXISTE" : "NO EXISTE") . "\n";

$col_info = $mysqli->query("SHOW COLUMNS FROM support_tickets LIKE 'categoria'");
$col_row  = $col_info ? $col_info->fetch_assoc() : null;
echo "4. categoria ENUM: " . ($col_row['Type'] ?? 'NO ENCONTRADO') . "\n";
$has_seguridad = $col_row && strpos($col_row['Type'] ?? '', 'seguridad') !== false;
echo "5. has_seguridad: " . ($has_seguridad ? "SI" : "NO") . "\n";

$tickets = db_query($mysqli,
    "SELECT * FROM support_tickets WHERE tenant_id = ? ORDER BY created_at DESC",
    'i', [$user['id']]);
echo "6. Tickets count: " . count($tickets ?? []) . "\n";

if ($has_files_table && $tickets) {
    $ids = implode(',', array_map('intval', array_column($tickets, 'id')));
    echo "7. Ticket IDs: $ids\n";
    if ($ids) {
        $files_rows = db_query($mysqli,
            "SELECT * FROM support_ticket_files WHERE ticket_id IN ($ids)");
        echo "8. Files rows: " . count($files_rows ?? []) . "\n";
    }
}

echo "9. Llegó al final sin errores\n";
echo "</pre>";
