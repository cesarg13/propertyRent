<?php
// =============================================================
//  PropertyRent — CRON: Enviar alertas de email
//  Ejecutar: diariamente a las 08:00
//  Crontab:  0 8 * * * php /var/www/html/cron/send_alerts.php >> /var/log/dpr_alerts.log 2>&1
// =============================================================

if (php_sapi_name() !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'])) {
    http_response_code(403); die('Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

$hoy    = date('Y-m-d');
$log    = [];
$sent   = 0;

echo "[" . date('Y-m-d H:i:s') . "] PropertyRent — Envío de alertas\n";

// ---- 1. Recordatorios de vencimiento próximo ----
// Buscar pagos que vencen en X días (según config de cada inmueble)
$por_vencer = db_query($mysqli,
    "SELECT p.id,
            DATEDIFF(p.fecha_vencimiento, CURDATE()) AS dias_restantes
     FROM payments p
     JOIN leases l  ON l.id = p.lease_id AND l.estado = 'activo'
     JOIN units un  ON un.id = l.unit_id
     JOIN properties pr ON pr.id = un.property_id
     LEFT JOIN mora_config mc ON (mc.property_id = pr.id OR mc.property_id IS NULL) AND mc.activo=1
     WHERE p.estado IN ('pendiente')
       AND DATEDIFF(p.fecha_vencimiento, CURDATE()) IN (0,1,3,5)
     GROUP BY p.id
     ORDER BY p.fecha_vencimiento ASC");

foreach ($por_vencer as $pay) {
    dpr_notify_vencimiento_proximo($mysqli, $pay);
    echo "  → Recordatorio vencimiento payment #{$pay['id']} — {$pay['dias_restantes']} días\n";
    $sent++;
}

// ---- 2. Notificación de mora (primer día en mora + cada 7 días) ----
$morosos = db_query($mysqli,
    "SELECT p.id, DATEDIFF(CURDATE(), p.fecha_vencimiento) AS dias_venc
     FROM payments p
     JOIN leases l ON l.id=p.lease_id AND l.estado='activo'
     WHERE p.estado = 'moroso'
       AND (
         DATEDIFF(CURDATE(), p.fecha_vencimiento) = 1
         OR MOD(DATEDIFF(CURDATE(), p.fecha_vencimiento), 7) = 0
       )");

foreach ($morosos as $m) {
    dpr_notify_mora_aplicada($mysqli, $m['id']);
    echo "  → Mora payment #{$m['id']} — {$m['dias_venc']} días vencido\n";
    $sent++;
}

// ---- 3. Resumen diario para el admin (solo lunes y viernes) ----
$dow = (int)date('N'); // 1=Lun … 7=Dom
if (in_array($dow, [1, 5])) {
    dpr_notify_admin_daily_summary($mysqli);
    echo "  → Resumen diario enviado a admins\n";
    $sent++;
}

echo "[" . date('Y-m-d H:i:s') . "] Emails enviados: $sent\n\n";
