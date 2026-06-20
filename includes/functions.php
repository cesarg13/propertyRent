<?php
// =============================================================
//  PropertyRent — Funciones de negocio compartidas
// =============================================================

// ------------------------------------------------------------------
// MORA
// ------------------------------------------------------------------

function dpr_calcular_mora(mysqli $db, int $lease_id, float $valor_arriendo, string $fecha_vencimiento): array {
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
    $hoy  = new DateTime();
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

function dpr_fecha_vencimiento(int $dia_inicio, string $periodo_ym): string {
    $dia_venc = max(1, $dia_inicio - 5);
    return $periodo_ym . '-' . str_pad($dia_venc, 2, '0', STR_PAD_LEFT);
}

function dpr_generar_periodos(string $fecha_inicio, ?string $fecha_fin = null): array {
    $periodos   = [];
    $inicio     = new DateTime($fecha_inicio);
    $fin        = $fecha_fin ? new DateTime($fecha_fin) : new DateTime();
    $fin->modify('+1 month');
    $dia_inicio = (int)$inicio->format('d');
    $cursor     = clone $inicio;
    $cursor->modify('first day of this month');
    while ($cursor <= $fin) {
        $ym         = $cursor->format('Y-m');
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
// MIME TYPE — sin depender de la extensión fileinfo
// ------------------------------------------------------------------

/**
 * Detecta el MIME type usando múltiples métodos como fallback.
 * No depende de fileinfo estando habilitado.
 */
function dpr_mime_type(string $tmp_path): string {
    // Método 1: finfo (si está disponible)
    if (function_exists('finfo_open') && defined('FILEINFO_MIME_TYPE')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = finfo_file($fi, $tmp_path);
            finfo_close($fi);
            if ($mime) return $mime;
        }
    }

    // Método 2: mime_content_type (disponible en la mayoría de servidores)
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmp_path);
        if ($mime) return $mime;
    }

    // Método 3: leer magic bytes del archivo (fallback manual)
    $handle = @fopen($tmp_path, 'rb');
    if ($handle) {
        $bytes = fread($handle, 12);
        fclose($handle);
        // JPEG: FF D8 FF
        if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
        // PNG: 89 50 4E 47
        if (substr($bytes, 0, 4) === "\x89PNG")       return 'image/png';
        // WebP: RIFF....WEBP
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
        // GIF: GIF87a or GIF89a
        if (substr($bytes, 0, 6) === 'GIF87a' || substr($bytes, 0, 6) === 'GIF89a') return 'image/gif';
        // PDF: %PDF
        if (substr($bytes, 0, 4) === '%PDF') return 'application/pdf';
    }

    // Último recurso: por extensión
    $ext = strtolower(pathinfo($tmp_path, PATHINFO_EXTENSION));
    $ext_map = [
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',  'webp' => 'image/webp',
        'gif'  => 'image/gif',  'pdf'  => 'application/pdf',
    ];
    return $ext_map[$ext] ?? 'application/octet-stream';
}

// ------------------------------------------------------------------
// UPLOAD seguro de archivos (sin depender de fileinfo)
// ------------------------------------------------------------------

function dpr_upload_file(array $file, string $subfolder = 'comprobantes'): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo supera el límite del servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el límite del formulario.',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente.',
            UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Carpeta temporal no disponible.',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo.',
        ];
        return ['success' => false, 'error' => $errors[$file['error']] ?? 'Error al subir archivo (código ' . $file['error'] . ')'];
    }

    if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
        return ['success' => false, 'error' => 'El archivo supera ' . MAX_UPLOAD_MB . ' MB'];
    }

    $mime = dpr_mime_type($file['tmp_name']);

    if (!in_array($mime, ALLOWED_MIME, true)) {
        return ['success' => false, 'error' => 'Tipo de archivo no permitido (' . $mime . '). Solo JPG, PNG, WEBP, PDF.'];
    }

    $ext_map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
    $ext      = $ext_map[$mime] ?? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir      = UPLOAD_PATH . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'No se pudo guardar el archivo en el servidor.'];
    }

    return [
        'success'  => true,
        'filename' => $filename,
        'url'      => UPLOAD_URL . $subfolder . '/' . $filename,
        'mime'     => $mime,
    ];
}

// ------------------------------------------------------------------
// UPLOAD con redimensión a WebP (sin depender de fileinfo)
// ------------------------------------------------------------------

function dpr_upload_image(array $file, string $subfolder = 'fotos', int $max_px = 800): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return dpr_upload_file($file, $subfolder); // reutiliza mensajes de error
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'La imagen supera 10 MB'];
    }

    $mime = dpr_mime_type($file['tmp_name']);

    // Si no es imagen, usar upload normal (PDF, etc.)
    $img_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $img_mimes, true)) {
        return dpr_upload_file($file, $subfolder);
    }

    // Crear GD image según tipo
    $src = null;
    if ($mime === 'image/jpeg')      $src = @imagecreatefromjpeg($file['tmp_name']);
    elseif ($mime === 'image/png')   $src = @imagecreatefrompng($file['tmp_name']);
    elseif ($mime === 'image/webp')  $src = @imagecreatefromwebp($file['tmp_name']);
    elseif ($mime === 'image/gif')   $src = @imagecreatefromgif($file['tmp_name']);

    if (!$src) {
        // GD falló, subir como está
        return dpr_upload_file($file, $subfolder);
    }

    $ow = imagesx($src);
    $oh = imagesy($src);

    // Calcular nuevo tamaño manteniendo proporción
    if ($ow > $max_px || $oh > $max_px) {
        $ratio = $ow >= $oh ? $max_px / $ow : $max_px / $oh;
        $nw    = (int)round($ow * $ratio);
        $nh    = (int)round($oh * $ratio);
    } else {
        $nw = $ow;
        $nh = $oh;
    }

    $dst = imagecreatetruecolor($nw, $nh);

    // Preservar transparencia para PNG y WebP
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagedestroy($src);

    $dir = UPLOAD_PATH . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Intentar WebP primero, fallback a JPEG
    $saved = false;
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

    if (function_exists('imagewebp')) {
        $dest = $dir . $filename . '.webp';
        if (imagewebp($dst, $dest, 82)) {
            $filename .= '.webp';
            $saved = true;
            $out_mime = 'image/webp';
        }
    }

    if (!$saved) {
        $dest = $dir . $filename . '.jpg';
        imagejpeg($dst, $dest, 85);
        $filename .= '.jpg';
        $out_mime = 'image/jpeg';
    }

    imagedestroy($dst);

    return [
        'success'  => true,
        'filename' => $filename,
        'url'      => UPLOAD_URL . $subfolder . '/' . $filename,
        'mime'     => $out_mime,
        'size'     => [$nw, $nh],
    ];
}

// ------------------------------------------------------------------
// SERVICIOS PÚBLICOS — calcular consumo
// ------------------------------------------------------------------

function dpr_calcular_consumo(float $lectura_anterior, float $lectura_actual, float $tarifa, float $cargo_fijo = 0): array {
    $consumo = max(0, $lectura_actual - $lectura_anterior);
    $valor   = round($consumo * $tarifa + $cargo_fijo, 0);
    return [
        'consumo'    => $consumo,
        'valor'      => $valor,
        'tarifa'     => $tarifa,
        'cargo_fijo' => $cargo_fijo,
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
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN estado='ocupada' THEN 1 ELSE 0 END) AS ocupadas
         FROM units WHERE estado != 'inactiva'");

    $ocu = $ocupacion[0] ?? ['total' => 1, 'ocupadas' => 0];
    $pct = $ocu['total'] > 0 ? round($ocu['ocupadas'] / $ocu['total'] * 100) : 0;

    return [
        'recaudado'   => (float)($recaudado[0]['total']  ?? 0),
        'morosos'     => (int)  ($morosos[0]['total']    ?? 0),
        'validando'   => (int)  ($pendientes[0]['total'] ?? 0),
        'ocupacion'   => $pct,
        'ocupadas'    => (int)$ocu['ocupadas'],
        'total_units' => (int)$ocu['total'],
        'periodo'     => $periodo,
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
    $pay = db_query($db, "SELECT * FROM payments WHERE id = ?", 'i', [$payment_id]);
    if (!$pay) return ['success' => false, 'error' => 'Pago no encontrado'];
    $p = $pay[0];

    $monto_pagado_nuevo = (float)($p['monto_pagado'] ?? 0) + $monto;
    $total              = (float)$p['valor_total'];

    // Insertar abono
    $r = db_execute($db,
        "INSERT INTO payment_partials
            (payment_id, lease_id, tipo, monto, metodo, referencia, comprobante_url, nota, subido_por, rol_subido)
         VALUES (?, ?, 'abono', ?, ?, ?, ?, ?, ?, ?)",
        'iidsssssi',
        [$payment_id, $lease_id, $monto, $metodo, $referencia, $comprobante_url, $nota, $user_id, $rol]
    );
    if (!$r['success']) return ['success' => false, 'error' => 'No se pudo registrar el abono'];

    $saldo_a_favor = 0.0;

    if ($monto_pagado_nuevo >= $total) {
        $nuevo_estado  = 'pagado';
        $excedente     = $monto_pagado_nuevo - $total;
        $saldo_a_favor = $excedente;
        if ($excedente > 0) {
            db_execute($db,
                "UPDATE leases SET saldo_favor = saldo_favor + ? WHERE id = ?",
                'di', [$excedente, $lease_id]
            );
        }
    } else {
        // Admin valida directamente → pendiente. Inquilino → validando para aprobación.
        $nuevo_estado = ($rol === 'tenant') ? 'validando' : 'pendiente';
    }

    db_execute($db,
        "UPDATE payments SET monto_pagado = ?, estado = ?, tiene_abonos = 1, updated_at = NOW() WHERE id = ?",
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
// PAGOS PARCIALES — aplicar saldo a favor
// ------------------------------------------------------------------

function dpr_aplicar_saldo_favor(mysqli $db, int $lease_id, int $payment_id, int $user_id): array {
    $lease = db_query($db, "SELECT saldo_favor FROM leases WHERE id = ?", 'i', [$lease_id]);
    if (!$lease || (float)$lease[0]['saldo_favor'] <= 0) {
        return ['success' => false, 'error' => 'Sin saldo a favor disponible'];
    }
    $saldo = (float)$lease[0]['saldo_favor'];

    $pay = db_query($db, "SELECT * FROM payments WHERE id = ?", 'i', [$payment_id]);
    if (!$pay) return ['success' => false, 'error' => 'Pago no encontrado'];
    $p = $pay[0];

    $pendiente = max(0, (float)$p['valor_total'] - (float)($p['monto_pagado'] ?? 0));
    $aplicar   = min($saldo, $pendiente);
    if ($aplicar <= 0) return ['success' => false, 'error' => 'El pago ya está cubierto'];

    db_execute($db,
        "INSERT INTO payment_partials
            (payment_id, lease_id, tipo, monto, metodo, nota, subido_por, rol_subido)
         VALUES (?, ?, 'saldo_favor_aplicado', ?, 'otro', 'Saldo a favor aplicado', ?, 'admin')",
        'iidi', [$payment_id, $lease_id, $aplicar, $user_id]
    );

    $nuevo_monto  = (float)($p['monto_pagado'] ?? 0) + $aplicar;
    $nuevo_estado = $nuevo_monto >= (float)$p['valor_total'] ? 'pagado' : $p['estado'];

    db_execute($db,
        "UPDATE payments SET monto_pagado = ?, estado = ?, tiene_abonos = 1, updated_at = NOW() WHERE id = ?",
        'dsi', [$nuevo_monto, $nuevo_estado, $payment_id]
    );
    db_execute($db,
        "UPDATE leases SET saldo_favor = saldo_favor - ? WHERE id = ?",
        'di', [$aplicar, $lease_id]
    );

    dpr_audit_log($db, $user_id, 'SALDO_FAVOR', 'payments', $payment_id,
        "Aplicó " . number_format($aplicar) . " de saldo a favor");

    return ['success' => true, 'aplicado' => $aplicar, 'nuevo_estado' => $nuevo_estado];
}

// dpr_current_user() está definida en includes/auth_check.php
