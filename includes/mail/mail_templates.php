<?php
// =============================================================
//  PropertyRent — Templates de email en HTML
//  Todas las notificaciones del sistema pasan por aquí.
// =============================================================

/**
 * Envuelve contenido en el layout base de email HTML.
 */
function dpr_email_layout(string $title, string $body_html, string $footer_note = ''): string {
    $app   = APP_NAME;
    $color = '#0f2d52';
    $blue  = '#1a5fa8';
    $year  = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:32px 0">
    <tr><td align="center">
      <table width="580" cellpadding="0" cellspacing="0" style="max-width:580px;width:100%">
        <!-- Header -->
        <tr>
          <td style="background:{$color};border-radius:12px 12px 0 0;padding:24px 32px">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td>
                  <div style="color:#fff;font-size:16px;font-weight:700">{$app}</div>
                  <div style="color:rgba(255,255,255,.5);font-size:11px;margin-top:2px">Sistema de arrendamientos</div>
                </td>
                <td align="right">
                  <div style="width:36px;height:36px;background:rgba(255,255,255,.12);border-radius:8px;display:inline-flex;align-items:center;justify-content:center">
                    <span style="color:#fff;font-size:18px">🏠</span>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="background:#fff;padding:32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0">
            {$body_html}
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:16px 32px;text-align:center">
            <div style="font-size:11px;color:#94a3b8">
              {$footer_note}
              Este es un correo automático de {$app}. No respondas a este mensaje.<br>
              &copy; {$year} {$app}
            </div>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Bloque de resumen (tabla de datos clave en el email).
 */
function dpr_email_info_table(array $rows): string {
    $html = '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:16px 0">';
    foreach ($rows as $label => $value) {
        $html .= <<<ROW
        <tr>
          <td style="padding:10px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #e2e8f0;width:40%">{$label}</td>
          <td style="padding:10px 16px;font-size:13px;color:#1e293b;border-bottom:1px solid #e2e8f0;font-weight:500">{$value}</td>
        </tr>
ROW;
    }
    $html .= '</table>';
    return $html;
}

/**
 * Botón de acción primario para emails.
 */
function dpr_email_button(string $label, string $url): string {
    return <<<BTN
    <div style="text-align:center;margin:24px 0">
      <a href="{$url}" style="display:inline-block;background:#1a5fa8;color:#fff;text-decoration:none;
         padding:12px 28px;border-radius:8px;font-size:14px;font-weight:600">{$label}</a>
    </div>
BTN;
}

/**
 * Bloque de alerta (danger/warning/success) dentro del email.
 */
function dpr_email_alert(string $message, string $type = 'info'): string {
    $styles = [
        'success' => ['#d1fae5','#065f46','#10b981'],
        'warning' => ['#fef3c7','#92400e','#f59e0b'],
        'danger'  => ['#fee2e2','#991b1b','#ef4444'],
        'info'    => ['#dbeafe','#1e40af','#3b82f6'],
    ];
    [$bg, $tx, $bd] = $styles[$type] ?? $styles['info'];
    return <<<ALERT
    <div style="background:{$bg};border-left:4px solid {$bd};border-radius:0 8px 8px 0;padding:12px 16px;margin:16px 0;font-size:13px;color:{$tx}">
      {$message}
    </div>
ALERT;
}
