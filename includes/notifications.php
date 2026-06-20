<?php
// =============================================================
//  PropertyRent — Notificaciones por email
//  Incluir en functions.php o requerir por separado.
//  Todas las funciones dpr_notify_*() van aquí.
// =============================================================

require_once __DIR__ . '/mail/mailer.php';
require_once __DIR__ . '/mail/mail_templates.php';

// ----------------------------------------------------------------
// Helpers internos
// ----------------------------------------------------------------

/** Obtiene datos completos de un pago con inquilino, unidad e inmueble. */
function _dpr_get_payment_context(mysqli $db, int $payment_id): ?array {
    $rows = db_query($db,
        "SELECT p.*,
                CONCAT(u.nombre,' ',u.apellido) AS inquilino,
                u.email AS tenant_email,
                un.nombre AS unidad,
                pr.nombre AS inmueble,
                l.dia_vencimiento,
                adm.email AS admin_email,
                CONCAT(adm.nombre,' ',adm.apellido) AS admin_nombre
         FROM payments p
         JOIN leases l      ON l.id = p.lease_id
         JOIN users u       ON u.id = l.tenant_id
         JOIN units un      ON un.id = l.unit_id
         JOIN properties pr ON pr.id = un.property_id
         JOIN users adm     ON adm.rol = 'admin' AND adm.estado='activo'
         WHERE p.id = ?
         ORDER BY adm.id ASC
         LIMIT 1",
        'i', [$payment_id]);
    return $rows ? $rows[0] : null;
}

// ================================================================
// 1. Inquilino: pago aprobado
// ================================================================
function dpr_notify_payment_approved(mysqli $db, int $payment_id): void {
    $ctx = _dpr_get_payment_context($db, $payment_id);
    if (!$ctx) return;

    $info = dpr_email_info_table([
        'Inmueble / Unidad' => h($ctx['inmueble']) . ' / ' . h($ctx['unidad']),
        'Periodo'           => h($ctx['periodo']),
        'Valor pagado'      => fmt_money((float)$ctx['valor_total']),
        'Fecha de registro' => date('d/m/Y H:i'),
        'Validado por'      => h($ctx['admin_nombre']),
    ]);

    $nota_html = $ctx['nota_admin']
        ? dpr_email_alert('Nota del administrador: ' . h($ctx['nota_admin']), 'info')
        : '';

    $body = <<<BODY
    <h2 style="color:#0f2d52;font-size:20px;margin:0 0 8px">✅ Pago confirmado</h2>
    <p style="color:#475569;font-size:14px;margin:0 0 16px">
      Hola <strong>{$ctx['inquilino']}</strong>, tu pago ha sido revisado y <strong>aprobado</strong> por el administrador.
    </p>
    {$info}
    {$nota_html}
    <p style="font-size:13px;color:#64748b;margin-top:16px">
      Puedes descargar tu comprobante iniciando sesión en el portal.
    </p>
BODY;

    $html = dpr_email_layout('Pago confirmado — ' . APP_NAME, $body);
    dpr_send_mail($ctx['tenant_email'], $ctx['inquilino'],
        APP_NAME . ' — Pago del período ' . $ctx['periodo'] . ' confirmado', $html);
}

// ================================================================
// 2. Inquilino: comprobante rechazado
// ================================================================
function dpr_notify_payment_rejected(mysqli $db, int $payment_id, string $motivo = ''): void {
    $ctx = _dpr_get_payment_context($db, $payment_id);
    if (!$ctx) return;

    $info = dpr_email_info_table([
        'Inmueble / Unidad' => h($ctx['inmueble']) . ' / ' . h($ctx['unidad']),
        'Periodo'           => h($ctx['periodo']),
        'Valor pendiente'   => fmt_money((float)$ctx['valor_total']),
        'Fecha límite'      => fmt_date($ctx['fecha_vencimiento']),
    ]);

    $motivo_html = $motivo
        ? dpr_email_alert('<strong>Motivo del rechazo:</strong> ' . h($motivo), 'warning')
        : dpr_email_alert('El comprobante no pudo ser verificado. Por favor sube un comprobante válido (imagen o PDF legible).', 'warning');

    $body = <<<BODY
    <h2 style="color:#991b1b;font-size:20px;margin:0 0 8px">❌ Comprobante rechazado</h2>
    <p style="color:#475569;font-size:14px;margin:0 0 16px">
      Hola <strong>{$ctx['inquilino']}</strong>, lamentamos informarte que tu comprobante de pago no fue aprobado.
    </p>
    {$info}
    {$motivo_html}
    <p style="font-size:13px;color:#475569;margin-top:8px">
      Por favor ingresa al portal y sube un nuevo comprobante antes de la fecha límite para evitar intereses de mora.
    </p>
BODY;

    $html = dpr_email_layout('Comprobante rechazado — ' . APP_NAME, $body);
    dpr_send_mail($ctx['tenant_email'], $ctx['inquilino'],
        APP_NAME . ' — Acción requerida: comprobante rechazado', $html);
}

// ================================================================
// 3. Admin: nuevo comprobante subido por inquilino
// ================================================================
function dpr_notify_admin_new_comprobante(mysqli $db, int $payment_id): void {
    $ctx = _dpr_get_payment_context($db, $payment_id);
    if (!$ctx || !$ctx['admin_email']) return;

    $info = dpr_email_info_table([
        'Inquilino'         => h($ctx['inquilino']),
        'Inmueble / Unidad' => h($ctx['inmueble']) . ' / ' . h($ctx['unidad']),
        'Periodo'           => h($ctx['periodo']),
        'Valor declarado'   => fmt_money((float)$ctx['valor_total']),
        'Nota del inquilino'=> $ctx['nota_inquilino'] ? h($ctx['nota_inquilino']) : '—',
        'Hora de recepción' => date('d/m/Y H:i:s'),
    ]);

    $btn = dpr_email_button(
        'Revisar comprobante',
        BASE_URL . '/admin/payments_admin.php?estado=validando'
    );

    $body = <<<BODY
    <h2 style="color:#0f2d52;font-size:20px;margin:0 0 8px">📋 Nuevo comprobante por revisar</h2>
    <p style="color:#475569;font-size:14px;margin:0 0 16px">
      Un inquilino ha subido un comprobante de pago. Debes revisarlo y aprobarlo o rechazarlo.
    </p>
    {$info}
    {$btn}
    <p style="font-size:12px;color:#94a3b8;text-align:center">
      Tienes hasta 24 horas hábiles para dar respuesta al inquilino.
    </p>
BODY;

    $html = dpr_email_layout('Comprobante pendiente — ' . APP_NAME, $body);
    dpr_send_mail($ctx['admin_email'], $ctx['admin_nombre'],
        APP_NAME . ' — Nuevo comprobante: ' . $ctx['inquilino'] . ' · ' . $ctx['periodo'], $html);
}

// ================================================================
// 4. Inquilino: alerta de vencimiento próximo (cron)
// ================================================================
function dpr_notify_vencimiento_proximo(mysqli $db, array $payment): void {
    $ctx = _dpr_get_payment_context($db, $payment['id']);
    if (!$ctx) return;

    $dias_txt = $payment['dias_restantes'] == 0
        ? '¡<strong>hoy</strong> es el último día!'
        : 'en <strong>' . $payment['dias_restantes'] . ' día(s)</strong>';

    $mora = dpr_calcular_mora($db, $ctx['lease_id'], (float)$ctx['valor_arriendo'], $ctx['fecha_vencimiento']);

    $info = dpr_email_info_table([
        'Inmueble / Unidad' => h($ctx['inmueble']) . ' / ' . h($ctx['unidad']),
        'Periodo'           => h($ctx['periodo']),
        'Valor a pagar'     => fmt_money((float)$ctx['valor_arriendo']),
        'Fecha límite'      => fmt_date($ctx['fecha_vencimiento']),
        'Tasa de mora'      => $mora['tasa'] . '% mensual tras ' . (int)5 . ' días de gracia',
    ]);

    $alerta = dpr_email_alert(
        "Recuerda que el pago vence $dias_txt. Después del vencimiento se aplicará una mora del {$mora['tasa']}% mensual.",
        'warning'
    );

    $body = <<<BODY
    <h2 style="color:#92400e;font-size:20px;margin:0 0 8px">⏰ Recordatorio de pago</h2>
    <p style="color:#475569;font-size:14px;margin:0 0 16px">
      Hola <strong>{$ctx['inquilino']}</strong>, este es un recordatorio de tu próximo vencimiento de arriendo.
    </p>
    {$info}
    {$alerta}
    <p style="font-size:13px;color:#475569;margin-top:8px">
      Ingresa al portal para subir tu comprobante de pago.
    </p>
BODY;

    $html = dpr_email_layout('Recordatorio de pago — ' . APP_NAME, $body);
    dpr_send_mail($ctx['tenant_email'], $ctx['inquilino'],
        APP_NAME . ' — Recordatorio: pago del período ' . $ctx['periodo'], $html);
}

// ================================================================
// 5. Inquilino: notificación de mora aplicada
// ================================================================
function dpr_notify_mora_aplicada(mysqli $db, int $payment_id): void {
    $ctx = _dpr_get_payment_context($db, $payment_id);
    if (!$ctx) return;

    $mora = dpr_calcular_mora($db, $ctx['lease_id'], (float)$ctx['valor_arriendo'], $ctx['fecha_vencimiento']);
    $total = $ctx['valor_arriendo'] + $mora['valor_mora'];

    $info = dpr_email_info_table([
        'Inmueble / Unidad' => h($ctx['inmueble']) . ' / ' . h($ctx['unidad']),
        'Periodo vencido'   => h($ctx['periodo']),
        'Arriendo'          => fmt_money((float)$ctx['valor_arriendo']),
        'Días en mora'      => $mora['dias_mora'] . ' días',
        'Interés calculado' => fmt_money($mora['valor_mora']) . ' (' . $mora['tasa'] . '% mensual)',
        'Total a pagar'     => fmt_money($total),
    ]);

    $alerta = dpr_email_alert(
        '⚠️ Este valor aumenta diariamente hasta que realices el pago. Contáctate con el administrador si tienes dificultades.',
        'danger'
    );

    $body = <<<BODY
    <h2 style="color:#991b1b;font-size:20px;margin:0 0 8px">⚠️ Interés de mora generado</h2>
    <p style="color:#475569;font-size:14px;margin:0 0 16px">
      Hola <strong>{$ctx['inquilino']}</strong>, tu pago se encuentra vencido y se han generado intereses de mora.
    </p>
    {$info}
    {$alerta}
BODY;

    $html = dpr_email_layout('Aviso de mora — ' . APP_NAME, $body,
        'Para acuerdos de pago comunícate directamente con el administrador. ');
    dpr_send_mail($ctx['tenant_email'], $ctx['inquilino'],
        APP_NAME . ' — Pago vencido: mora aplicada al período ' . $ctx['periodo'], $html);
}

// ================================================================
// 6. Admin: resumen diario (enviado por cron)
// ================================================================
function dpr_notify_admin_daily_summary(mysqli $db): void {
    $admins = db_query($db,
        "SELECT id, nombre, apellido, email FROM users WHERE rol='admin' AND estado='activo'");
    if (!$admins) return;

    $periodo = date('Y-m');
    $kpis    = dpr_kpis($db);

    // Morosos del día
    $morosos = db_query($db,
        "SELECT CONCAT(u.nombre,' ',u.apellido) AS inquilino,
                pr.nombre AS inmueble, un.nombre AS unidad,
                p.periodo, p.valor_mora, p.valor_total,
                DATEDIFF(CURDATE(), p.fecha_vencimiento) AS dias
         FROM payments p
         JOIN leases l  ON l.id=p.lease_id
         JOIN users u   ON u.id=l.tenant_id
         JOIN units un  ON un.id=l.unit_id
         JOIN properties pr ON pr.id=un.property_id
         WHERE p.estado='moroso'
         ORDER BY p.fecha_vencimiento ASC LIMIT 10");

    $morosos_html = '';
    foreach ($morosos as $m) {
        $morosos_html .= "<tr>
            <td style='padding:8px 12px;font-size:12px;border-bottom:1px solid #e2e8f0'>" . h($m['inquilino']) . "</td>
            <td style='padding:8px 12px;font-size:12px;border-bottom:1px solid #e2e8f0'>" . h($m['inmueble'] . '/' . $m['unidad']) . "</td>
            <td style='padding:8px 12px;font-size:12px;border-bottom:1px solid #e2e8f0'>" . h($m['periodo']) . "</td>
            <td style='padding:8px 12px;font-size:12px;border-bottom:1px solid #e2e8f0;color:#dc2626'>" . fmt_money($m['valor_total']) . "</td>
            <td style='padding:8px 12px;font-size:12px;border-bottom:1px solid #e2e8f0'>{$m['dias']}d</td>
          </tr>";
    }

    $tabla_morosos = $morosos ? "
        <h3 style='color:#991b1b;font-size:14px;margin:24px 0 8px'>Cartera en mora</h3>
        <table width='100%' style='border:1px solid #e2e8f0;border-radius:8px;border-collapse:collapse'>
          <thead>
            <tr style='background:#fef2f2'>
              <th style='padding:8px 12px;font-size:11px;text-align:left;color:#64748b'>Inquilino</th>
              <th style='padding:8px 12px;font-size:11px;text-align:left;color:#64748b'>Unidad</th>
              <th style='padding:8px 12px;font-size:11px;text-align:left;color:#64748b'>Periodo</th>
              <th style='padding:8px 12px;font-size:11px;text-align:left;color:#64748b'>Total</th>
              <th style='padding:8px 12px;font-size:11px;text-align:left;color:#64748b'>Días</th>
            </tr>
          </thead>
          <tbody>{$morosos_html}</tbody>
        </table>" : '';

    $info = dpr_email_info_table([
        'Periodo'              => $periodo,
        'Recaudado'            => fmt_money($kpis['recaudado']),
        'Ocupación'            => $kpis['ocupacion'] . '% (' . $kpis['ocupadas'] . '/' . $kpis['total_units'] . ' unidades)',
        'Unidades en mora'     => $kpis['morosos'],
        'Comprobantes pendientes' => $kpis['validando'],
    ]);

    $btn = dpr_email_button('Ir al dashboard', BASE_URL . '/admin/dashboard.php');

    $body = <<<BODY
    <h2 style="color:#0f2d52;font-size:20px;margin:0 0 8px">📊 Resumen diario — {$periodo}</h2>
    <p style="color:#475569;font-size:14px;margin:0 0 16px">Aquí tienes el resumen del estado actual del sistema.</p>
    {$info}
    {$tabla_morosos}
    {$btn}
BODY;

    $html = dpr_email_layout('Resumen diario — ' . APP_NAME, $body);

    foreach ($admins as $admin) {
        dpr_send_mail(
            $admin['email'],
            $admin['nombre'] . ' ' . $admin['apellido'],
            APP_NAME . ' — Resumen diario ' . date('d/m/Y'),
            $html
        );
    }
}

// ================================================================
// 7. Bienvenida a nuevo inquilino
// ================================================================
function dpr_notify_welcome_tenant(mysqli $db, int $user_id, string $temp_password = ''): void {
    $rows = db_query($db, "SELECT * FROM users WHERE id=?", 'i', [$user_id]);
    if (!$rows) return;
    $u = $rows[0];

    $pass_block = $temp_password
        ? dpr_email_alert("Tu contraseña temporal es: <strong style='font-size:16px;letter-spacing:2px'>$temp_password</strong><br>Cámbiala al ingresar por primera vez.", 'info')
        : '';

    $btn = dpr_email_button('Acceder al portal', BASE_URL . '/index.php');

    $body = <<<BODY
    <h2 style="color:#0f2d52;font-size:20px;margin:0 0 8px">🎉 Bienvenido al portal</h2>
    <p style="color:#475569;font-size:14px;margin:0 0 16px">
      Hola <strong>{$u['nombre']}</strong>, el administrador ha creado tu cuenta en <strong>{$app_name}</strong>.
      Desde aquí podrás consultar tu estado de cuenta, subir comprobantes de pago y revisar documentos.
    </p>
    {$pass_block}
    <p style="font-size:13px;color:#64748b;margin-top:8px">Tu correo de acceso es: <strong>{$u['email']}</strong></p>
    {$btn}
BODY;
    $body = str_replace('{$app_name}', APP_NAME, $body);

    $html = dpr_email_layout('Bienvenido — ' . APP_NAME, $body);
    dpr_send_mail($u['email'], $u['nombre'] . ' ' . $u['apellido'],
        'Bienvenido a ' . APP_NAME, $html);
}
