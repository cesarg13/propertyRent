<?php
// =============================================================
//  PropertyRent — Funciones de negocio compartidas
// =============================================================

// ------------------------------------------------------------------
// MORA
// ------------------------------------------------------------------

/**
 * Calcula el valor de mora para un pago vencido.
 * Usa la configuración más específica disponible (por inmueble > global).
 */
function dpr_calcular_mora(mysqli $db, int $lease_id, float $valor_arriendo, string $fecha_vencimiento): array {
    // Obtener config de mora aplicable
    $sql = "SELECT mc.tasa_mora_mensual, mc.dias_gracia
            FROM mora_config mc
            JOIN leases l ON l.id = ?
            JOIN units u  ON u.id = l.unit_id
            WHERE (mc.property_id = u.property_id OR mc.property_id IS NULL)
              AND mc.activo = 1
            ORDER BY mc.property_id DESC
            LIMIT 1";
    $rows = db_query($db, $sql, 'i', [$lease_id]);
    if (!$rows) {
        return ['dias_mora' => 0, 'valor_mora' => 0.00, 'tasa' => 0];
    }
    $cfg = $rows[0];
    $hoy = new DateTime();
    $venc = new DateTime($fecha_vencimiento);
    $diff = (int)$hoy->diff($venc)->days;
    $dias_mora = $hoy > $venc ? $diff - (int)$cfg['dias_gracia'] : 0;
    if ($dias_mora <= 0) {
        return ['dias_mora' => 0, 'valor_mora' => 0.00, 'tasa' => $cfg['tasa_mora_mensual']];
    }
    $tasa_diaria = $cfg['tasa_mora_mensual'] / 100 / 30;
    $valor_mora  = round($valor_arriendo * $tasa_diaria * $dias_mora, 0);
    return [
        'dias_mora'  => $dias_mora,
        'valor_mora' => $valor_mora,
        'tasa'       => $cfg['tasa_mora_mensual'],
    ];
}

// ------------------------------------------------------------------
// FECHAS DE PAGO
// ------------------------------------------------------------------

/**
 * Dado el día de inicio del contrato, calcula la fecha de vencimiento
 * del próximo período. Adelantado: vence 5 días antes de iniciar el período.
 *
 * Ejemplo: contrato inicia el 15, pago del mes de Mayo vence el 10 de Mayo.
 */
function dpr_fecha_vencimiento(int $dia_inicio, string $periodo_ym): string {
    // El pago adelantado vence dia_inicio - 5 del mismo mes del período
    $dia_venc = max(1, $dia_inicio - 5);
    return $periodo_ym . '-' . str_pad($dia_venc, 2, '0', STR_PAD_LEFT);
}

/**
 * Genera los períodos de pago para un contrato desde su inicio.
 * Retorna array de ['periodo' => 'YYYY-MM', 'fecha_vencimiento' => 'YYYY-MM-DD']
 */
function dpr_generar_periodos(string $fecha_inicio, ?string $fecha_fin = null): array {
    $periodos = [];
    $inicio = new DateTime($fecha_inicio);
    $fin    = $fecha_fin ? new DateTime($fecha_fin) : new DateTime();
    $fin->modify('+1 month');
    $dia_inicio = (int)$inicio->format('d');
    $cursor = clone $inicio;
    $cursor->modify('first day of this month');
    while ($cursor <= $fin) {
        $ym = $cursor->format('Y-m');
        $periodos[] = [
            'periodo'           => $ym,
            'fecha_vencimiento' => dpr_fecha_vencimiento($dia_inicio, $ym),
        ];
        $cursor->modify('+1 month');
    }
    return $periodos;
}

// ------------------------------------------------------------------
// AUDIT LOG
// ------------------------------------------------------------------

function dpr_audit_log(mysqli $db, int $user_id, string $accion, string $tabla, ?int $registro_id, string $detalle = ''): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    db_execute($db,
        "INSERT INTO audit_log (user_id, accion, tabla, registro_id, detalle, ip, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())",
        'ississ',
        [$user_id, $accion, $tabla, $registro_id, $detalle, $ip]
    );
}

// ------------------------------------------------------------------
// UPLOAD seguro de archivos
// ------------------------------------------------------------------

function dpr_upload_file(array $file, string $subfolder = 'comprobantes'): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Error al subir archivo (código ' . $file['error'] . ')'];
    }
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_MIME, true)) {
        return ['success' => false, 'error' => 'Tipo de archivo no permitido: ' . $mime];
    }
    if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
        return ['success' => false, 'error' => 'El archivo supera ' . MAX_UPLOAD_MB . ' MB'];
    }
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
    $dir      = UPLOAD_PATH . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest     = $dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'No se pudo mover el archivo al servidor'];
    }
    return [
        'success'  => true,
        'filename' => $filename,
        'url'      => UPLOAD_URL . $subfolder . '/' . $filename,
        'mime'     => $mime,
    ];
}

// ------------------------------------------------------------------
// SERVICIOS PÚBLICOS — calcular consumo por m³ o kWh
// ------------------------------------------------------------------

/**
 * Calcula el valor a cobrar según lectura de medidor.
 * $lectura_anterior y $lectura_actual en la unidad del servicio.
 * $tarifa = precio por unidad consumida.
 */
function dpr_calcular_consumo(float $lectura_anterior, float $lectura_actual, float $tarifa, float $cargo_fijo = 0): array {
    $consumo = max(0, $lectura_actual - $lectura_anterior);
    $valor   = round($consumo * $tarifa + $cargo_fijo, 0);
    return [
        'consumo'     => $consumo,
        'valor'       => $valor,
        'tarifa'      => $tarifa,
        'cargo_fijo'  => $cargo_fijo,
    ];
}

// ------------------------------------------------------------------
// KPIs rápidos para el dashboard admin
// ------------------------------------------------------------------

function dpr_kpis(mysqli $db): array {
    $periodo = date('Y-m');

    $recaudado = db_query($db,
        "SELECT COALESCE(SUM(valor_total),0) AS total
         FROM payments WHERE estado='pagado' AND periodo=?", 's', [$periodo]);

    $morosos = db_query($db,
        "SELECT COUNT(DISTINCT lease_id) AS total
         FROM payments WHERE estado='moroso' AND periodo=?", 's', [$periodo]);

    $pendientes = db_query($db,
        "SELECT COUNT(*) AS total FROM payments WHERE estado='validando'");

    $ocupacion = db_query($db,
        "SELECT
           COUNT(*) AS total,
           SUM(CASE WHEN estado='ocupada' THEN 1 ELSE 0 END) AS ocupadas
         FROM units WHERE estado != 'inactiva'");

    $ocu = $ocupacion[0] ?? ['total'=>1,'ocupadas'=>0];
    $pct = $ocu['total'] > 0 ? round($ocu['ocupadas'] / $ocu['total'] * 100) : 0;

    return [
        'recaudado'   => (float)($recaudado[0]['total']   ?? 0),
        'morosos'     => (int)  ($morosos[0]['total']     ?? 0),
        'validando'   => (int)  ($pendientes[0]['total']  ?? 0),
        'ocupacion'   => $pct,
        'ocupadas'    => (int)$ocu['ocupadas'],
        'total_units' => (int)$ocu['total'],
        'periodo'     => $periodo,
    ];
}

// ------------------------------------------------------------------
// UPLOAD CON REDIMENSIÓN AUTOMÁTICA A WEBP (max 800px por lado)
// ------------------------------------------------------------------

function dpr_upload_image(array $file, string $subfolder = 'fotos', int $max_px = 800): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Error al subir imagen (código ' . $file['error'] . ')'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed_img = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($mime, $allowed_img, true)) {
        // Si no es imagen intentamos el upload normal (para PDF etc.)
        return dpr_upload_file($file, $subfolder);
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'La imagen supera 10 MB'];
    }
    // Crear GD image
    $src = match($mime) {
        'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => imagecreatefrompng($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
        'image/gif'  => imagecreatefromgif($file['tmp_name']),
        default      => false,
    };
    if (!$src) {
        return ['success' => false, 'error' => 'No se pudo procesar la imagen'];
    }
    $ow = imagesx($src); $oh = imagesy($src);
    // Calcular nuevo tamaño manteniendo proporción
    if ($ow > $max_px || $oh > $max_px) {
        $ratio = $ow > $oh ? $max_px / $ow : $max_px / $oh;
        $nw = (int)round($ow * $ratio);
        $nh = (int)round($oh * $ratio);
    } else {
        $nw = $ow; $nh = $oh;
    }
    $dst = imagecreatetruecolor($nw, $nh);
    // Transparencia para PNG/WebP
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagedestroy($src);

    $dir = UPLOAD_PATH . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.webp';
    $dest = $dir . $filename;

    // Guardar como WebP (calidad 82)
    if (function_exists('imagewebp') && imagewebp($dst, $dest, 82)) {
        imagedestroy($dst);
    } else {
        // Fallback a JPEG si WebP no disponible
        $filename = str_replace('.webp', '.jpg', $filename);
        $dest = $dir . $filename;
        imagejpeg($dst, $dest, 85);
        imagedestroy($dst);
    }
    return [
        'success'  => true,
        'filename' => $filename,
        'url'      => UPLOAD_URL . $subfolder . '/' . $filename,
        'mime'     => 'image/webp',
        'size'     => [$nw, $nh],
    ];
}

// ------------------------------------------------------------------
// PAGOS PARCIALES — registrar abono
// ------------------------------------------------------------------

function dpr_registrar_abono(
    mysqli $db,
    int $payment_id,
    int $lease_id,
    float $monto,
    string $metodo,
    string $referencia,
    ?string $comprobante_url,
    string $nota,
    int $user_id,
    string $rol = 'admin'
): array {
    // Obtener pago actual
    $pay = db_query($db, "SELECT * FROM payments WHERE id=?", 'i', [$payment_id]);
    if (!$pay) return ['success' => false, 'error' => 'Pago no encontrado'];
    $p = $pay[0];

    $monto_pagado_nuevo = (float)$p['monto_pagado'] + $monto;
    $total = (float)$p['valor_total'];

    // Insertar abono
    $r = db_execute($db,
        "INSERT INTO payment_partials (payment_id,lease_id,tipo,monto,metodo,referencia,comprobante_url,nota,subido_por,rol_subido)
         VALUES (?,?,'abono',?,?,?,?,?,?,?)",
        'iidsssssi',
        [$payment_id, $lease_id, $monto, $metodo, $referencia, $comprobante_url, $nota, $user_id, $rol]
    );
    if (!$r['success']) return ['success' => false, 'error' => 'No se pudo registrar el abono'];

    // Determinar nuevo estado del pago
    $nuevo_estado = $p['estado'];
    $saldo_a_favor = 0.0;

    if ($monto_pagado_nuevo >= $total) {
        $nuevo_estado  = 'pagado';
        $excedente     = $monto_pagado_nuevo - $total;
        $saldo_a_favor = $excedente;
        if ($excedente > 0) {
            // Sumar excedente al saldo a favor del contrato
            db_execute($db,
                "UPDATE leases SET saldo_favor = saldo_favor + ? WHERE id = ?",
                'di', [$excedente, $lease_id]
            );
        }
    } else {
        $nuevo_estado = 'validando'; // Con abono parcial queda en validación hasta completar
    }

    // Actualizar payment
    db_execute($db,
        "UPDATE payments SET monto_pagado=?, estado=?, tiene_abonos=1, updated_at=NOW()
         WHERE id=?",
        'dsi', [$monto_pagado_nuevo, $nuevo_estado, $payment_id]
    );

    return [
        'success'       => true,
        'insert_id'     => $r['insert_id'],
        'monto_pagado'  => $monto_pagado_nuevo,
        'nuevo_estado'  => $nuevo_estado,
        'saldo_a_favor' => $saldo_a_favor,
    ];
}

// ------------------------------------------------------------------
// SALDO A FAVOR — aplicar a un pago
// ------------------------------------------------------------------

function dpr_aplicar_saldo_favor(mysqli $db, int $lease_id, int $payment_id, int $user_id): array {
    $lease = db_query($db, "SELECT saldo_favor FROM leases WHERE id=?", 'i', [$lease_id]);
    if (!$lease || (float)$lease[0]['saldo_favor'] <= 0) {
        return ['success' => false, 'error' => 'Sin saldo a favor disponible'];
    }
    $saldo = (float)$lease[0]['saldo_favor'];
    $pay   = db_query($db, "SELECT * FROM payments WHERE id=?", 'i', [$payment_id]);
    if (!$pay) return ['success' => false, 'error' => 'Pago no encontrado'];
    $p = $pay[0];
    $pendiente = max(0, (float)$p['valor_total'] - (float)$p['monto_pagado']);
    $aplicar   = min($saldo, $pendiente);
    if ($aplicar <= 0) return ['success' => false, 'error' => 'El pago ya está cubierto'];

    // Registrar como abono especial
    db_execute($db,
        "INSERT INTO payment_partials (payment_id,lease_id,tipo,monto,metodo,nota,subido_por,rol_subido)
         VALUES (?,?,'saldo_favor_aplicado',?,'otro','Saldo a favor aplicado automáticamente',?,'admin')",
        'iiidi', [$payment_id, $lease_id, $aplicar, $user_id]
    );
    $nuevo_monto_pagado = (float)$p['monto_pagado'] + $aplicar;
    $nuevo_estado = $nuevo_monto_pagado >= (float)$p['valor_total'] ? 'pagado' : $p['estado'];
    db_execute($db,
        "UPDATE payments SET monto_pagado=?, estado=?, tiene_abonos=1, updated_at=NOW() WHERE id=?",
        'dsi', [$nuevo_monto_pagado, $nuevo_estado, $payment_id]
    );
    // Descontar saldo
    db_execute($db, "UPDATE leases SET saldo_favor = saldo_favor - ? WHERE id=?", 'di', [$aplicar, $lease_id]);
    dpr_audit_log($db, $user_id, 'SALDO_FAVOR', 'payments', $payment_id, "Aplicó \$$aplicar de saldo a favor");
    return ['success' => true, 'aplicado' => $aplicar, 'nuevo_estado' => $nuevo_estado];
}
