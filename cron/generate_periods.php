<?php
// =============================================================
//  PropertyRent — CRON: Generar períodos de pago del mes
//  Ejecutar el día 1 de cada mes a las 00:05
//  Crontab: 5 0 1 * * php /var/www/html/cron/generate_periods.php >> /var/log/dpr_periods.log 2>&1
// =============================================================

if (php_sapi_name() !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'])) {
    http_response_code(403); die('Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$periodo = date('Y-m');
$created = 0;

echo "[" . date('Y-m-d H:i:s') . "] Generando períodos para: $periodo\n";

// Todos los contratos activos
$leases = db_query($mysqli,
    "SELECT l.id, l.valor_mensual, l.fecha_inicio, l.dia_vencimiento
     FROM leases l WHERE l.estado = 'activo'");

foreach ($leases as $l) {
    // Verificar que el período ya no existe
    $existe = db_query($mysqli,
        "SELECT id FROM payments WHERE lease_id=? AND periodo=?",
        'is', [$l['id'], $periodo]);
    if ($existe) {
        echo "  → Lease #{$l['id']}: período $periodo ya existe. Omitido.\n";
        continue;
    }

    $fecha_venc = dpr_fecha_vencimiento($l['dia_vencimiento'], $periodo);

    $r = db_execute($mysqli,
        "INSERT INTO payments (lease_id, periodo, valor_arriendo, valor_mora, valor_total, fecha_vencimiento, estado)
         VALUES (?, ?, ?, 0, ?, ?, 'pendiente')",
        'isdds', [$l['id'], $periodo, $l['valor_mensual'], $l['valor_mensual'], $fecha_venc]);

    echo "  → Lease #{$l['id']}: creado period $periodo vence $fecha_venc\n";
    $created++;
}

echo "[" . date('Y-m-d H:i:s') . "] Períodos creados: $created\n\n";
