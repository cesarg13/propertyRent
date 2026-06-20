<?php
// =============================================================
//  PropertyRent — CRON: Calcular mora diaria
//  Ejecutar: php /ruta/cron/mora_calc.php
//  Crontab:  0 1 * * * php /var/www/html/cron/mora_calc.php >> /var/log/dpr_mora.log 2>&1
//
//  IMPORTANTE: este script solo se puede ejecutar por CLI o desde
//  una IP de servidor. Bloquear acceso HTTP via .htaccess (ya configurado).
// =============================================================

// Bloquear ejecución desde HTTP
if (php_sapi_name() !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'])) {
    http_response_code(403); die('Forbidden');
}

define('CLI_RUN', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$hoy     = date('Y-m-d');
$periodo = date('Y-m');
$log     = [];

echo "[" . date('Y-m-d H:i:s') . "] PropertyRent — Cálculo de mora\n";

// 1. Obtener todos los pagos pendientes/morosos vencidos con contrato activo
$pagos = db_query($mysqli,
    "SELECT p.id, p.lease_id, p.periodo, p.valor_arriendo, p.fecha_vencimiento, p.estado,
            mc.tasa_mora_mensual, mc.dias_gracia
     FROM payments p
     JOIN leases l  ON l.id = p.lease_id AND l.estado = 'activo'
     JOIN units un  ON un.id = l.unit_id
     JOIN properties pr ON pr.id = un.property_id
     LEFT JOIN mora_config mc ON (mc.property_id = pr.id OR mc.property_id IS NULL) AND mc.activo = 1
     WHERE p.estado IN ('pendiente', 'moroso')
       AND p.fecha_vencimiento < CURDATE()
     ORDER BY p.lease_id, mc.property_id DESC");

// Agrupar por pago — tomar config más específica (property_id no nulo tiene prioridad)
$processed = [];
foreach ($pagos as $p) {
    if (isset($processed[$p['id']])) continue;
    $processed[$p['id']] = $p;
}

$updated = 0;
foreach ($processed as $p) {
    $tasa    = (float)($p['tasa_mora_mensual'] ?? 1.5);
    $gracia  = (int)($p['dias_gracia'] ?? 5);

    $venc   = new DateTime($p['fecha_vencimiento']);
    $hoyDt  = new DateTime($hoy);
    $dias_desde_venc = (int)$hoyDt->diff($venc)->days;
    $dias_mora = max(0, $dias_desde_venc - $gracia);

    if ($dias_mora <= 0) continue; // Dentro del período de gracia

    $tasa_diaria = $tasa / 100 / 30;
    $valor_mora  = round($p['valor_arriendo'] * $tasa_diaria * $dias_mora, 0);
    $valor_total = $p['valor_arriendo'] + $valor_mora;

    // Actualizar estado y mora en payment
    db_execute($mysqli,
        "UPDATE payments SET estado='moroso', valor_mora=?, valor_total=?, updated_at=NOW() WHERE id=?",
        'ddi', [$valor_mora, $valor_total, $p['id']]);

    // Registrar en late_fees (historial diario)
    db_execute($mysqli,
        "INSERT INTO late_fees (payment_id, lease_id, dias_mora, meses_mora, capital_base, tasa_aplicada, valor_mora, fecha_calculo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE dias_mora=VALUES(dias_mora), valor_mora=VALUES(valor_mora)",
        'iiidddd s',
        [$p['id'], $p['lease_id'], $dias_mora, (int)floor($dias_mora/30),
         $p['valor_arriendo'], $tasa, $valor_mora, $hoy]);

    $updated++;
    echo "  → Payment #{$p['id']} lease #{$p['lease_id']} periodo {$p['periodo']}: {$dias_mora}d mora → $" . number_format($valor_mora,0) . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Mora calculada: $updated pagos actualizados.\n\n";
