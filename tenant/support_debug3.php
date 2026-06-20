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

$tbl = $mysqli->query("SHOW TABLES LIKE 'support_ticket_files'");
$has_files_table = $tbl && $tbl->num_rows > 0;

$col_info      = $mysqli->query("SHOW COLUMNS FROM support_tickets LIKE 'categoria'");
$col_row       = $col_info ? $col_info->fetch_assoc() : null;
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

// Probar el loop completo ticket por ticket
echo "<pre>";
foreach ($tickets as $idx => $t) {
    echo "Ticket $idx id={$t['id']} estado={$t['estado']} prioridad={$t['prioridad']}\n";
    flush();

    // Test spill
    $spill = 'neutral';
    if     ($t['estado'] === 'abierto')    $spill = 'info';
    elseif ($t['estado'] === 'en_proceso') $spill = 'warn';
    elseif ($t['estado'] === 'resuelto')   $spill = 'ok';
    echo "  spill=$spill\n";

    // Test ppill
    $ppill = 'neutral';
    if     ($t['prioridad'] === 'urgente') $ppill = 'danger';
    elseif ($t['prioridad'] === 'alta')    $ppill = 'warn';
    elseif ($t['prioridad'] === 'media')   $ppill = 'info';
    echo "  ppill=$ppill\n";

    // Test fmt_date
    echo "  created_at raw: " . var_export($t['created_at'], true) . "\n";
    echo "  fmt_date: " . fmt_date($t['created_at']) . "\n";

    // Test h() on fields
    echo "  asunto: " . h($t['asunto']) . "\n";
    echo "  categoria: " . h($t['categoria']) . "\n";
    echo "  descripcion len: " . strlen($t['descripcion']) . "\n";
    echo "  nl2br+h: " . substr(nl2br(h($t['descripcion'])), 0, 60) . "\n";

    // Test respuesta
    echo "  respuesta: " . var_export($t['respuesta'], true) . "\n";
    if ($t['respuesta']) {
        echo "  nl2br respuesta: " . substr(nl2br(h($t['respuesta'])), 0, 60) . "\n";
    }

    // Test adjuntos
    $adj = $archivos_map[(int)$t['id']] ?? [];
    echo "  adjuntos: " . count($adj) . "\n";
    foreach ($adj as $af) {
        echo "  archivo url=" . $af['url'] . " mime=" . ($af['mime'] ?? 'null') . "\n";
        $is_img = strpos($af['mime'] ?? '', 'image/') === 0;
        echo "  mb_strimwidth: " . mb_strimwidth($af['filename'], 0, 24, '…') . "\n";
    }

    echo "  Ticket $idx OK\n\n";
}
echo "Loop completo OK\n";

// Test page variables
$page_title  = 'Soporte';
$active_menu = 'support';
echo "page_title=$page_title active_menu=$active_menu\n";

// Test header
try {
    ob_start();
    require_once __DIR__ . '/../includes/header.php';
    $out = ob_get_clean();
    echo "Header OK\n";
    echo $out;
} catch (Throwable $e) {
    ob_end_clean();
    echo "Header ERROR: " . $e->getMessage() . " línea " . $e->getLine() . "\n";
}
echo "</pre>";
