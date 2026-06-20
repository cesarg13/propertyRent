<?php
// =============================================================
//  PropertyRent — Motor de envío de emails
//  Auto-detecta PHPMailer; si no está, usa mail() nativo.
//  Uso:
//    $ok = dpr_send_mail('dest@email.com', 'Asunto', '<h1>HTML</h1>', 'Texto plano');
// =============================================================

require_once __DIR__ . '/mail_config.php';

/**
 * Envía un correo. Retorna [success=>bool, error=>string|null].
 *
 * @param string      $to_email  Dirección destino
 * @param string      $to_name   Nombre del destinatario
 * @param string      $subject   Asunto
 * @param string      $html      Cuerpo en HTML
 * @param string|null $plain     Cuerpo en texto plano (auto-generado si null)
 * @param array       $attachments  [['path'=>'/ruta', 'name'=>'archivo.pdf'], ...]
 */
function dpr_send_mail(
    string  $to_email,
    string  $to_name,
    string  $subject,
    string  $html,
    ?string $plain       = null,
    array   $attachments = []
): array {
    if (!$plain) {
        $plain = strip_tags(str_replace(['<br>','<br/>','</p>','</li>'], "\n", $html));
    }

    // --------------------------------------------------------
    // Intentar PHPMailer (Composer autoload o descarga manual)
    // --------------------------------------------------------
    $autoload  = __DIR__ . '/../../vendor/autoload.php';
    $manual    = PHPMAILER_PATH . 'PHPMailer.php';

    $has_phpmailer = false;
    if (file_exists($autoload)) {
        require_once $autoload;
        $has_phpmailer = class_exists('PHPMailer\PHPMailer\PHPMailer');
    } elseif (file_exists($manual)) {
        require_once $manual;
        require_once PHPMAILER_PATH . 'SMTP.php';
        require_once PHPMAILER_PATH . 'Exception.php';
        $has_phpmailer = true;
    }

    if ($has_phpmailer) {
        return _dpr_send_phpmailer($to_email, $to_name, $subject, $html, $plain, $attachments);
    }

    // --------------------------------------------------------
    // Fallback: mail() nativo con MIME multipart
    // --------------------------------------------------------
    return _dpr_send_native($to_email, $to_name, $subject, $html, $plain, $attachments);
}

// ============================================================
// PHPMailer SMTP
// ============================================================
function _dpr_send_phpmailer(
    string $to_email, string $to_name, string $subject,
    string $html, string $plain, array $attachments
): array {
    $ns = class_exists('PHPMailer\PHPMailer\PHPMailer')
        ? 'PHPMailer\PHPMailer\PHPMailer'
        : 'PHPMailer';

    try {
        $mail = new $ns(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_SECURE;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPDebug  = MAIL_DEBUG;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_FROM_NAME);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $plain;

        foreach ($attachments as $att) {
            if (!empty($att['path']) && file_exists($att['path'])) {
                $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
            }
        }

        $mail->send();
        return ['success' => true, 'error' => null];

    } catch (\Exception $e) {
        error_log("DPR Mail PHPMailer error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================================
// Fallback: mail() nativo con cabeceras MIME
// ============================================================
function _dpr_send_native(
    string $to_email, string $to_name, string $subject,
    string $html, string $plain, array $attachments
): array {
    $boundary = '----=_Part_' . md5(uniqid('', true));
    $to       = $to_name ? "\"$to_name\" <$to_email>" : $to_email;
    $from     = '"' . MAIL_FROM_NAME . '" <' . MAIL_FROM_EMAIL . '>';

    $headers  = "From: $from\r\n";
    $headers .= "Reply-To: " . MAIL_REPLY_TO . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $headers .= "X-Mailer: DemoPropertyRent\r\n";

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plain)) . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($html)) . "\r\n";
    $body .= "--$boundary--\r\n";

    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (!$ok) {
        error_log("DPR Mail native error: mail() returned false for $to_email");
        return ['success' => false, 'error' => 'mail() falló. Verifica sendmail en el servidor.'];
    }
    return ['success' => true, 'error' => null];
}
