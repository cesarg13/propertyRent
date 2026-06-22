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

// ====================================================================
// LEDGER DE PAGOS (rediseño) — usado por admin/payments_admin2.php
// Tablas: obligations, payments_received, payment_applications
// Estas funciones NO tocan payments / payment_partials (módulo viejo).
// Las que combinan varios INSERT/UPDATE usan transacción explícita:
// o se aplica todo, o no se aplica nada.
// ====================================================================

/** Lista de conceptos válidos — única fuente de verdad para selects y validación. */
function dpr_conceptos_ledger(): array {
    return [
        'alquiler' => 'Alquiler',
        'agua'     => 'Servicio Agua',
        'energia'  => 'Servicio Energía',
        'gas'      => 'Servicio Gas',
        'aseo'     => 'Aseo',
        'multa'    => 'Multa',
        'otro'     => 'Otro',
    ];
}

/**
 * Crea una obligación (deuda) por concepto.
 */
function dpr_crear_obligacion(
    mysqli $db,
    int $lease_id,
    string $concepto,
    ?string $periodo,
    float $valor,
    ?string $fecha_limite,
    string $nota,
    string $origen,
    ?int $meter_reading_id,
    int $user_id
): array {
    if ($valor <= 0) return ['success' => false, 'error' => 'El valor de la obligación debe ser mayor a 0.'];
    if (!array_key_exists($concepto, dpr_conceptos_ledger())) {
        return ['success' => false, 'error' => 'Concepto inválido.'];
    }
    $r = db_execute($db,
        "INSERT INTO obligations
            (lease_id, concepto, periodo, valor_calculado, fecha_limite, nota, origen, meter_reading_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'issdsssii',
        [$lease_id, $concepto, $periodo, $valor, $fecha_limite, $nota, $origen, $meter_reading_id, $user_id]
    );
    if ($r['success']) {
        dpr_audit_log($db, $user_id, 'INSERT', 'obligations', $r['insert_id'],
            "Creó obligación $concepto " . fmt_money($valor) . ($periodo ? " (periodo $periodo)" : '') . " [$origen]");
    }
    return $r;
}

/**
 * Registra un pago recibido y lo reparte entre conceptos en una sola transacción.
 *
 * $aplicaciones = [ ['obligation_id' => int|null, 'concepto' => string, 'monto' => float, 'nota' => string|null], ... ]
 * La suma de $aplicaciones DEBE igualar $monto_total cuando $rol='admin' (se valida acá, no se asume).
 *
 * $rol = 'admin'  → estado='aprobado' inmediato (admin ya repartió, ya sabe a qué aplica).
 * $rol = 'tenant' → estado='validando', SIN aplicaciones todavía (el admin las define al aprobar).
 *   En ese caso, pasar $aplicaciones = [] y el pago queda pendiente hasta dpr_aprobar_pago_ledger().
 */
function dpr_registrar_pago_ledger(
    mysqli $db,
    int $lease_id,
    float $monto_total,
    string $metodo,
    string $referencia,
    ?string $comprobante_url,
    string $nota,
    string $fecha_pago,
    int $user_id,
    string $rol,
    array $aplicaciones
): array {
    if ($monto_total <= 0) {
        return ['success' => false, 'error' => 'El monto debe ser mayor a 0.'];
    }

    if ($rol === 'tenant') {
        if ($aplicaciones) {
            return ['success' => false, 'error' => 'Un pago de inquilino no debe traer reparto; lo define el admin al aprobar.'];
        }
        $estado = 'validando';
    } else {
        $suma = 0.0;
        foreach ($aplicaciones as $a) { $suma += (float)$a['monto']; }
        // Tolerancia de 1 peso por redondeos de decimal->float.
        if (abs($suma - $monto_total) > 1.0) {
            return ['success' => false, 'error' => 'La suma de los conceptos (' . fmt_money($suma) . ') no coincide con el monto del pago (' . fmt_money($monto_total) . ').'];
        }
        if (!$aplicaciones) {
            return ['success' => false, 'error' => 'Debes repartir el pago en al menos un concepto.'];
        }
        $estado = 'aprobado';
    }

    $db->begin_transaction();
    try {
        $r = db_execute($db,
            "INSERT INTO payments_received
                (lease_id, monto_total, metodo, referencia, comprobante_url, nota, fecha_pago, recibido_por, rol_origen, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'idsssssiss',
            [$lease_id, $monto_total, $metodo, $referencia, $comprobante_url, $nota, $fecha_pago, $user_id, $rol, $estado]
        );
        if (!$r['success']) throw new RuntimeException('No se pudo registrar el pago: ' . $r['error']);
        $payment_id = $r['insert_id'];

        foreach ($aplicaciones as $a) {
            $obligation_id = $a['obligation_id'] !== null ? (int)$a['obligation_id'] : null;
            $concepto      = (string)$a['concepto'];
            $monto         = (float)$a['monto'];
            if ($monto <= 0) throw new RuntimeException('Cada aplicación debe tener monto mayor a 0.');
            if (!array_key_exists($concepto, dpr_conceptos_ledger())) {
                throw new RuntimeException("Concepto inválido en aplicación: $concepto");
            }
            $ra = db_execute($db,
                "INSERT INTO payment_applications (payment_id, obligation_id, concepto, monto, nota, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)",
                'iisdsi',
                [$payment_id, $obligation_id, $concepto, $monto, $a['nota'] ?? null, $user_id]
            );
            if (!$ra['success']) throw new RuntimeException('No se pudo registrar la aplicación: ' . $ra['error']);
        }

        dpr_audit_log($db, $user_id, 'INSERT', 'payments_received', $payment_id,
            "Registró pago ($rol): " . fmt_money($monto_total) . " ($metodo $referencia)" . ($rol === 'tenant' ? ' — pendiente de validar' : ''));

        $db->commit();
        return ['success' => true, 'payment_id' => $payment_id, 'estado' => $estado];
    } catch (Throwable $e) {
        $db->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Aprueba un pago de inquilino (estado='validando') y define el reparto por
 * concepto en el mismo paso — el admin ve el comprobante y decide a qué aplica.
 * Todo en una transacción: si el reparto no cuadra, no se aprueba nada.
 */
function dpr_aprobar_pago_ledger(mysqli $db, int $payment_id, array $aplicaciones, string $nota_admin, int $user_id): array {
    $pay = db_query($db, "SELECT * FROM payments_received WHERE id=?", 'i', [$payment_id]);
    if (!$pay) return ['success' => false, 'error' => 'Pago no encontrado.'];
    $p = $pay[0];
    if ($p['estado'] !== 'validando') {
        return ['success' => false, 'error' => 'Este pago no está pendiente de validación (estado actual: ' . $p['estado'] . ').'];
    }

    $suma = 0.0;
    foreach ($aplicaciones as $a) { $suma += (float)$a['monto']; }
    if (abs($suma - (float)$p['monto_total']) > 1.0) {
        return ['success' => false, 'error' => 'La suma de los conceptos (' . fmt_money($suma) . ') no coincide con el monto del pago (' . fmt_money((float)$p['monto_total']) . ').'];
    }
    if (!$aplicaciones) {
        return ['success' => false, 'error' => 'Debes repartir el pago en al menos un concepto antes de aprobar.'];
    }

    $db->begin_transaction();
    try {
        foreach ($aplicaciones as $a) {
            $obligation_id = $a['obligation_id'] !== null ? (int)$a['obligation_id'] : null;
            $concepto      = (string)$a['concepto'];
            $monto         = (float)$a['monto'];
            if ($monto <= 0) throw new RuntimeException('Cada aplicación debe tener monto mayor a 0.');
            $ra = db_execute($db,
                "INSERT INTO payment_applications (payment_id, obligation_id, concepto, monto, nota, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)",
                'iisdsi',
                [$payment_id, $obligation_id, $concepto, $monto, $a['nota'] ?? null, $user_id]
            );
            if (!$ra['success']) throw new RuntimeException('No se pudo registrar la aplicación: ' . $ra['error']);
        }

        db_execute($db,
            "UPDATE payments_received SET estado='aprobado', nota_admin=?, validado_por=?, fecha_validacion=NOW(), updated_at=NOW() WHERE id=?",
            'sii', [$nota_admin, $user_id, $payment_id]
        );

        dpr_audit_log($db, $user_id, 'VALIDATE', 'payments_received', $payment_id, "Aprobó pago de inquilino: $nota_admin");
        dpr_notify_payment_approved($db, $payment_id);

        $db->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        $db->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/** Rechaza un pago de inquilino pendiente de validación. Un solo UPDATE, no requiere transacción. */
function dpr_rechazar_pago_ledger(mysqli $db, int $payment_id, string $motivo, int $user_id): array {
    $pay = db_query($db, "SELECT estado FROM payments_received WHERE id=?", 'i', [$payment_id]);
    if (!$pay) return ['success' => false, 'error' => 'Pago no encontrado.'];
    if ($pay[0]['estado'] !== 'validando') {
        return ['success' => false, 'error' => 'Solo se pueden rechazar pagos en estado "validando".'];
    }
    db_execute($db,
        "UPDATE payments_received SET estado='rechazado', nota_admin=?, validado_por=?, fecha_validacion=NOW(), updated_at=NOW() WHERE id=?",
        'sii', [$motivo, $user_id, $payment_id]
    );
    dpr_audit_log($db, $user_id, 'REJECT', 'payments_received', $payment_id, "Rechazó pago: $motivo");
    dpr_notify_payment_rejected($db, $payment_id, $motivo);
    return ['success' => true];
}

/**
 * Anula una obligación (borrado lógico). Bloquea si ya tiene aplicaciones
 * encima, salvo que $force=true (uso explícito tras advertencia en UI).
 */
function dpr_anular_obligacion(mysqli $db, int $obligation_id, string $motivo, int $user_id, bool $force = false): array {
    $obl = db_query($db, "SELECT * FROM obligations WHERE id=?", 'i', [$obligation_id]);
    if (!$obl) return ['success' => false, 'error' => 'Obligación no encontrada.'];
    $o = $obl[0];
    if ($o['estado'] === 'anulada') return ['success' => false, 'error' => 'Esta obligación ya está anulada.'];

    $aplicado = db_query($db,
        "SELECT COALESCE(SUM(pa.monto),0) AS total FROM payment_applications pa
         JOIN payments_received pr ON pr.id = pa.payment_id
         WHERE pa.obligation_id = ? AND pr.estado = 'aprobado'",
        'i', [$obligation_id]);
    $total_aplicado = (float)($aplicado[0]['total'] ?? 0);

    if ($total_aplicado > 0 && !$force) {
        return [
            'success' => false,
            'error' => 'Esta obligación ya tiene ' . fmt_money($total_aplicado) . ' en pagos aplicados. Quita o reasigna esas aplicaciones antes de anularla.',
            'requiere_confirmacion' => true,
            'monto_aplicado' => $total_aplicado,
        ];
    }

    db_execute($db,
        "UPDATE obligations SET estado='anulada', nota_anulacion=?, updated_at=NOW() WHERE id=?",
        'si', [$motivo, $obligation_id]
    );
    dpr_audit_log($db, $user_id, 'DELETE', 'obligations', $obligation_id,
        "Anuló obligación {$o['concepto']} " . fmt_money((float)$o['valor_efectivo']) . " (periodo {$o['periodo']}). Motivo: $motivo"
        . ($total_aplicado > 0 ? " [FORZADO: tenía " . fmt_money($total_aplicado) . " aplicado]" : ''));

    return ['success' => true];
}

/**
 * Edita el valor_ajustado de una obligación. Bloquea si el nuevo valor
 * efectivo queda por debajo de lo ya aplicado — el admin debe reducir o
 * quitar aplicaciones primero; no se resuelve automático (regla de negocio
 * explícita, ver contexto de diseño).
 */
function dpr_editar_obligacion(
    mysqli $db,
    int $obligation_id,
    ?float $valor_ajustado,
    ?string $fecha_limite,
    string $nota,
    int $user_id
): array {
    $obl = db_query($db, "SELECT * FROM obligations WHERE id=?", 'i', [$obligation_id]);
    if (!$obl) return ['success' => false, 'error' => 'Obligación no encontrada.'];
    $o = $obl[0];
    if ($o['estado'] === 'anulada') return ['success' => false, 'error' => 'No se puede editar una obligación anulada.'];

    $valor_calculado = $o['valor_calculado'] !== null ? (float)$o['valor_calculado'] : null;
    $nuevo_efectivo  = $valor_ajustado ?? $valor_calculado ?? 0;

    $aplicado = db_query($db,
        "SELECT COALESCE(SUM(pa.monto),0) AS total FROM payment_applications pa
         JOIN payments_received pr ON pr.id = pa.payment_id
         WHERE pa.obligation_id = ? AND pr.estado = 'aprobado'",
        'i', [$obligation_id]);
    $total_aplicado = (float)($aplicado[0]['total'] ?? 0);

    if ($nuevo_efectivo < $total_aplicado) {
        return [
            'success' => false,
            'error' => 'No puedes bajar esta obligación a ' . fmt_money($nuevo_efectivo) . ': ya tiene ' . fmt_money($total_aplicado) . ' aplicado. Quita o reduce aplicaciones primero.',
        ];
    }

    db_execute($db,
        "UPDATE obligations SET valor_ajustado=?, fecha_limite=?, nota=?, updated_at=NOW() WHERE id=?",
        'dssi', [$valor_ajustado, $fecha_limite, $nota, $obligation_id]
    );
    dpr_audit_log($db, $user_id, 'UPDATE', 'obligations', $obligation_id,
        "Editó obligación {$o['concepto']}: valor " . fmt_money((float)$o['valor_efectivo']) . " → " . fmt_money($nuevo_efectivo) . ($nota ? ". Nota: $nota" : ''));

    return ['success' => true];
}

/**
 * Elimina una aplicación de pago individual (un "concepto" de un pago dividido).
 * Si el pago se queda sin ninguna aplicación tras esto, NO se borra el pago en
 * sí (sigue existiendo el registro de dinero recibido) — solo queda con 0
 * aplicaciones, visible como "sin asignar" en la tabla.
 */
function dpr_eliminar_aplicacion(mysqli $db, int $application_id, string $motivo, int $user_id): array {
    $app = db_query($db,
        "SELECT pa.*, pr.estado AS pago_estado FROM payment_applications pa
         JOIN payments_received pr ON pr.id = pa.payment_id
         WHERE pa.id = ?", 'i', [$application_id]);
    if (!$app) return ['success' => false, 'error' => 'Aplicación no encontrada.'];
    $a = $app[0];

    db_execute($db, "DELETE FROM payment_applications WHERE id=?", 'i', [$application_id]);
    dpr_audit_log($db, $user_id, 'DELETE', 'payment_applications', $application_id,
        "Eliminó aplicación de " . fmt_money((float)$a['monto']) . " (concepto {$a['concepto']}, pago #{$a['payment_id']})"
        . ($motivo ? ". Motivo: $motivo" : ''));

    return ['success' => true];
}

/** Saldo derivado de un lease (usa la vista v_lease_saldo). */
function dpr_saldo_lease(mysqli $db, int $lease_id): array {
    $row = db_query($db, "SELECT * FROM v_lease_saldo WHERE lease_id=?", 'i', [$lease_id]);
    return $row[0] ?? ['total_obligado' => 0, 'total_pagado' => 0, 'saldo_pendiente' => 0];
}

/**
 * Lista las obligaciones de un lease con aplicado/pendiente calculado.
 * A diferencia del endpoint AJAX obligaciones_lease de payments_admin2.php
 * (que solo trae las PENDIENTES, para armar un reparto), esta trae todas
 * las activas — el inquilino necesita ver también lo que ya pagó.
 */
function dpr_obligaciones_lease_detalle(mysqli $db, int $lease_id): array {
    $rows = db_query($db,
        "SELECT o.id, o.concepto, o.periodo, o.valor_efectivo, o.fecha_limite, o.origen, o.estado,
                COALESCE(SUM(CASE WHEN pr.estado='aprobado' THEN pa.monto ELSE 0 END), 0) AS aplicado
         FROM obligations o
         LEFT JOIN payment_applications pa ON pa.obligation_id = o.id
         LEFT JOIN payments_received pr ON pr.id = pa.payment_id
         WHERE o.lease_id = ? AND o.estado = 'activa'
         GROUP BY o.id
         ORDER BY o.fecha_limite ASC, o.id ASC",
        'i', [$lease_id]);
    foreach ($rows as &$r) {
        $r['valor_efectivo'] = (float)$r['valor_efectivo'];
        $r['aplicado'] = (float)$r['aplicado'];
        $r['pendiente'] = max(0, $r['valor_efectivo'] - $r['aplicado']);
    }
    unset($r);
    return $rows;
}

/** Pagos reportados por un lease, más recientes primero — para el historial del inquilino. */
function dpr_pagos_lease_historial(mysqli $db, int $lease_id, int $limit = 20): array {
    // Desempate por id DESC: created_at solo tiene resolución de segundo,
    // así que dos pagos registrados en el mismo segundo (común en pruebas
    // automatizadas, y posible en uso real con doble click o llamadas
    // concurrentes) necesitan un criterio secundario estable.
    return db_query($db,
        "SELECT * FROM payments_received WHERE lease_id = ? ORDER BY created_at DESC, id DESC LIMIT ?",
        'ii', [$lease_id, $limit]);
}

// ====================================================================
// PUENTE MEDIDORES -> LEDGER — usado por admin/meters.php
// ====================================================================

/**
 * Mapeo de tipo de servicio (meter_readings/public_services) a concepto
 * del ledger. 'internet' y 'administracion' (solo existen en factura
 * global, no en medidores) caen en 'otro' porque el ENUM de obligations
 * no los contempla como conceptos propios.
 */
function dpr_concepto_desde_tipo_servicio(string $tipo): string {
    $map = ['agua' => 'agua', 'energia' => 'energia', 'gas' => 'gas'];
    return $map[$tipo] ?? 'otro';
}

/**
 * Crea o actualiza (idempotente) la obligación de servicio ligada a una
 * lectura de medidor concreta. Idempotente por (lease_id, concepto, periodo,
 * origen='sistema'): si ya existe una obligación de sistema para ese
 * lease+concepto+periodo, se actualiza su valor_calculado y se reusa el
 * mismo registro en vez de duplicar — re-guardar la misma lectura del
 * mismo período no debe crear obligaciones repetidas.
 *
 * Importante: solo toca valor_calculado, NUNCA valor_ajustado — si el
 * admin ya hizo un ajuste manual en Pagos, una nueva lectura de medidor
 * no lo pisa (el ajuste sigue mandando vía valor_efectivo = COALESCE).
 *
 * Si la obligación existente ya tiene pagos aplicados por encima del
 * nuevo valor_calculado, NO se reduce silenciosamente (misma regla que
 * dpr_editar_obligacion) — se devuelve un aviso para que el admin lo
 * resuelva manualmente en Pagos.
 */
function dpr_upsert_obligacion_medidor(
    mysqli $db,
    int $lease_id,
    string $tipo_servicio,
    string $periodo,
    float $valor,
    ?string $fecha_limite,
    ?int $meter_reading_id,
    int $user_id
): array {
    // Normalizar: 0 significa "no hay lectura real asociada" (caso save_global,
    // reparto manual entre unidades sin medidor) — la columna tiene FK a
    // meter_readings, así que debe ir NULL, nunca 0 (violaría la FK).
    if ($meter_reading_id === 0) $meter_reading_id = null;

    $concepto = dpr_concepto_desde_tipo_servicio($tipo_servicio);

    $existente = db_query($db,
        "SELECT * FROM obligations
         WHERE lease_id=? AND concepto=? AND periodo=? AND origen='sistema' AND estado='activa'
         LIMIT 1",
        'iss', [$lease_id, $concepto, $periodo]);

    if ($existente) {
        $o = $existente[0];

        if ($valor <= 0) {
            // Consumo 0 / incluido en arriendo: no tiene sentido dejar una
            // obligación de $0 viva. Si no tiene pagos aplicados, se anula;
            // si ya tiene pagos aplicados, se deja intacta y se avisa.
            $r = dpr_anular_obligacion($db, (int)$o['id'], 'Lectura actualizada a consumo/valor 0 (incluido o sin consumo)', $user_id, false);
            if ($r['success']) {
                return ['success' => true, 'obligation_id' => (int)$o['id'], 'accion' => 'anulada'];
            }
            return ['success' => true, 'obligation_id' => (int)$o['id'], 'accion' => 'sin_cambios',
                'aviso' => 'La lectura quedó en $0 pero la obligación ya tiene pagos aplicados; revísala manualmente en Pagos.'];
        }

        $aplicado = db_query($db,
            "SELECT COALESCE(SUM(pa.monto),0) AS total FROM payment_applications pa
             JOIN payments_received pr ON pr.id = pa.payment_id
             WHERE pa.obligation_id = ? AND pr.estado = 'aprobado'",
            'i', [$o['id']]);
        $total_aplicado = (float)($aplicado[0]['total'] ?? 0);

        if ($valor < $total_aplicado) {
            return [
                'success' => true,
                'obligation_id' => (int)$o['id'],
                'accion' => 'sin_cambios',
                'aviso' => 'La nueva lectura sugiere ' . fmt_money($valor) . ', pero la obligación ya tiene ' . fmt_money($total_aplicado) . ' pagado. No se redujo el valor automáticamente — ajústalo manualmente en Pagos si corresponde.',
            ];
        }

        db_execute($db,
            "UPDATE obligations SET valor_calculado=?, meter_reading_id=COALESCE(?, meter_reading_id), fecha_limite=COALESCE(?, fecha_limite), updated_at=NOW() WHERE id=?",
            'disi',
            [$valor, $meter_reading_id, $fecha_limite, $o['id']]
        );
        dpr_audit_log($db, $user_id, 'UPDATE', 'obligations', (int)$o['id'],
            "Actualizó obligación $concepto desde medidor: " . fmt_money((float)$o['valor_calculado']) . " → " . fmt_money($valor));
        return ['success' => true, 'obligation_id' => (int)$o['id'], 'accion' => 'actualizada'];
    }

    if ($valor <= 0) {
        // Consumo 0 / incluido: no crear obligación de $0.
        return ['success' => true, 'obligation_id' => null, 'accion' => 'omitida'];
    }

    $r = dpr_crear_obligacion($db, $lease_id, $concepto, $periodo, $valor, $fecha_limite, '', 'sistema', $meter_reading_id, $user_id);
    if (!$r['success']) return $r;
    return ['success' => true, 'obligation_id' => $r['insert_id'], 'accion' => 'creada'];
}

/**
 * Devuelve un "renglón" de movimientos del ledger paginado para un conjunto
 * de leases (ya filtrados por inmueble/unidad/inquilino desde el caller).
 * $lease_ids vacío = sin filtro de lease (todos).
 */
function dpr_ledger_movimientos(mysqli $db, array $lease_ids, string $concepto_filtro, int $limit, int $offset): array {
    $where = ["1=1"];
    $types = '';
    $params = [];

    if ($lease_ids) {
        $in = implode(',', array_fill(0, count($lease_ids), '?'));
        $where[] = "lease_id IN ($in)";
        $types .= str_repeat('i', count($lease_ids));
        $params = array_merge($params, $lease_ids);
    }
    if ($concepto_filtro !== '') {
        $where[] = "concepto = ?";
        $types .= 's';
        $params[] = $concepto_filtro;
    }
    $whereSql = implode(' AND ', $where);

    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    return db_query($db,
        "SELECT * FROM v_ledger_movimientos
         WHERE $whereSql
         ORDER BY created_at DESC, row_key DESC
         LIMIT ? OFFSET ?",
        $types, $params
    );
}

/** Cuenta total de movimientos para la paginación (mismos filtros que dpr_ledger_movimientos, sin LIMIT). */
function dpr_ledger_movimientos_count(mysqli $db, array $lease_ids, string $concepto_filtro): int {
    $where = ["1=1"];
    $types = '';
    $params = [];

    if ($lease_ids) {
        $in = implode(',', array_fill(0, count($lease_ids), '?'));
        $where[] = "lease_id IN ($in)";
        $types .= str_repeat('i', count($lease_ids));
        $params = array_merge($params, $lease_ids);
    }
    if ($concepto_filtro !== '') {
        $where[] = "concepto = ?";
        $types .= 's';
        $params[] = $concepto_filtro;
    }
    $whereSql = implode(' AND ', $where);

    $row = db_query($db, "SELECT COUNT(*) AS n FROM v_ledger_movimientos WHERE $whereSql", $types, $params);
    return (int)($row[0]['n'] ?? 0);
}

// dpr_current_user() está definida en includes/auth_check.php
