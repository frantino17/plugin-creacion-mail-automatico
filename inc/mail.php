<?php
/**
 * inc/mail.php
 * Funciones de envío de emails para el plugin Solicitud.
 * Usa GLPIMailer (PHPMailer wrapper interno de GLPI).
 */

defined('GLPI_ROOT') || die('Security breach!');

// ─── Envío de email de aprobación al directivo ────────────────────────────────

/**
 * Envía el email con botones Aprobar / Rechazar al directivo.
 *
 * @param string $to           Email del directivo.
 * @param int    $ticketId     ID del ticket.
 * @param string $ticketTitle  Título/nombre del ticket.
 * @param string $approveUrl   URL completa para aprobar.
 * @param string $rejectUrl    URL completa para rechazar.
 */
function plugin_solicitud_send_approval_email(
    string $to,
    int    $ticketId,
    string $ticketTitle,
    string $approveUrl,
    string $rejectUrl
): void {
    $html = plugin_solicitud_approval_email_html(
        $ticketId,
        $ticketTitle,
        $approveUrl,
        $rejectUrl
    );

    plugin_solicitud_send_email(
        $to,
        "Solicitud de Aprobación — Ticket #$ticketId: $ticketTitle",
        $html
    );
}

// ─── Envío de notificación al área IT ────────────────────────────────────────

/**
 * Notifica al área IT sobre la decisión del directivo.
 *
 * @param string $itEmail
 * @param int    $ticketId
 * @param string $decision  'approve' | 'reject'
 */
function plugin_solicitud_notify_it(
    string $itEmail,
    int    $ticketId,
    string $decision
): void {
    $action  = ($decision === 'approve') ? 'APROBADA ✔' : 'RECHAZADA ✘';
    $subject = "Solicitud — Ticket #$ticketId: $action";

    $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
  .card{background:#fff;border-radius:8px;padding:30px;max-width:600px;margin:auto;
        box-shadow:0 2px 8px rgba(0,0,0,.1)}
  h2{margin-top:0}
  .badge-ok {color:#fff;background:#28a745;padding:6px 14px;border-radius:4px}
  .badge-ko {color:#fff;background:#dc3545;padding:6px 14px;border-radius:4px}
</style>
</head>
<body>
<div class="card">
  <h2>Notificación de Solicitud</h2>
  <p>El directivo ha <strong>$action</strong> la solicitud correspondiente al
     <strong>Ticket #$ticketId</strong>.</p>
  <p>Por favor, proceda según el resultado.</p>
  <hr>
  <small>Este mensaje fue generado automáticamente por el plugin Solicitud de GLPI.</small>
</div>
</body>
</html>
HTML;

    plugin_solicitud_send_email($itEmail, $subject, $html);
}

// ─── Función genérica de envío ────────────────────────────────────────────────

/**
 * Envía un email usando GLPIMailer (PHPMailer de GLPI).
 *
 * @param string $to       Destinatario.
 * @param string $subject  Asunto.
 * @param string $html     Cuerpo HTML.
 */
function plugin_solicitud_send_email(string $to, string $subject, string $html): void
{
    global $CFG_GLPI;

    try {
        $mailer = new GLPIMailer();

        // Remitente: usa la configuración de GLPI (forzar string, nunca null)
        $fromEmail = (string) ($CFG_GLPI['admin_email']      ?? 'noreply@glpi.local');
        $fromName  = (string) ($CFG_GLPI['admin_email_name'] ?? 'GLPI');
        if ($fromName === '') {
            $fromName = 'GLPI';
        }

        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addAddress($to, '');   // segundo arg '' obligatorio en GLPI 11
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body    = $html;
        $mailer->AltBody = strip_tags(
            str_replace(['<br>', '<br/>', '<br />'], "\n", $html)
        );

        $result = $mailer->send();

        // Log de éxito
        file_put_contents(
            GLPI_LOG_DIR . '/plugin_solicitud_mail.log',
            date('[Y-m-d H:i:s]') . " OK enviado a $to | Asunto: $subject\n",
            FILE_APPEND
        );

    } catch (\Throwable $e) {
        // Log del error real en archivo dedicado
        file_put_contents(
            GLPI_LOG_DIR . '/plugin_solicitud_mail.log',
            date('[Y-m-d H:i:s]') . " ERROR enviando a $to: " . $e->getMessage() . "\n"
            . "  SMTP host: " . ($CFG_GLPI['smtp_host'] ?? 'NO CONFIGURADO') . "\n"
            . "  SMTP port: " . ($CFG_GLPI['smtp_port'] ?? 'NO CONFIGURADO') . "\n"
            . "  Notif mail: " . ($CFG_GLPI['use_notifications'] ?? 'NO') . "\n",
            FILE_APPEND
        );
    }
}

// ─── Plantilla HTML del email de aprobación ───────────────────────────────────

/**
 * Genera el HTML del email de aprobación con botones.
 */
function plugin_solicitud_approval_email_html(
    int    $ticketId,
    string $ticketTitle,
    string $approveUrl,
    string $rejectUrl
): string {
    $ticketTitle = htmlspecialchars($ticketTitle, ENT_QUOTES);
    $approveUrl  = htmlspecialchars($approveUrl,  ENT_QUOTES);
    $rejectUrl   = htmlspecialchars($rejectUrl,   ENT_QUOTES);

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,Helvetica,sans-serif;background:#f0f2f5;padding:30px}
  .wrapper{max-width:620px;margin:auto}
  .header{background:#2d6cdf;color:#fff;padding:24px 30px;border-radius:8px 8px 0 0}
  .header h1{font-size:20px;font-weight:700}
  .body{background:#fff;padding:30px;border:1px solid #dde3ec;border-top:none}
  .body p{color:#444;line-height:1.6;margin-bottom:12px}
  .ticket-box{background:#f7f9ff;border-left:4px solid #2d6cdf;padding:14px 18px;
              margin:20px 0;border-radius:0 6px 6px 0}
  .ticket-box strong{color:#2d6cdf}
  .buttons{display:flex;gap:16px;margin-top:28px;flex-wrap:wrap}
  .btn{display:inline-block;padding:14px 32px;border-radius:6px;
       text-decoration:none;font-weight:700;font-size:15px;text-align:center;
       min-width:160px}
  .btn-approve{background:#28a745;color:#fff}
  .btn-reject {background:#dc3545;color:#fff}
  .btn-approve:hover{background:#218838}
  .btn-reject:hover {background:#c82333}
  .footer{background:#f7f9ff;padding:16px 30px;border:1px solid #dde3ec;
          border-top:none;border-radius:0 0 8px 8px;font-size:12px;color:#888}
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>Solicitud de Aprobación</h1>
  </div>
  <div class="body">
    <p>Se ha creado un nuevo ticket que requiere su aprobación:</p>

    <div class="ticket-box">
      <strong>Ticket #$ticketId</strong><br>
      $ticketTitle
    </div>

    <p>Por favor, haga clic en uno de los botones a continuación para tomar
       su decisión. <strong>No es necesario iniciar sesión en GLPI.</strong></p>

    <div class="buttons">
      <a href="$approveUrl" class="btn btn-approve">✔ Aprobar</a>
      <a href="$rejectUrl"  class="btn btn-reject">✘ Rechazar</a>
    </div>
  </div>
  <div class="footer">
    Este mensaje fue generado automáticamente. No responda a este correo.<br>
    GLPI — Sistema de Gestión de Tickets
  </div>
</div>
</body>
</html>
HTML;
}

// ─── Email al área de Cómputos con el formulario de alta ─────────────────────

/**
 * Envía email al área de Cómputos con link al formulario de alta de correo.
 * Usado desde el contexto GLPI (hook), por eso usa GLPIMailer.
 *
 * @param string $computosEmail  Email del área de Cómputos.
 * @param int    $ticketId       ID del ticket aprobado.
 * @param string $formUrl        URL completa al front/form.php con form_token.
 */
function plugin_solicitud_send_computos_email(
    string $computosEmail,
    int    $ticketId,
    string $formUrl
): void {
    $safeUrl = htmlspecialchars($formUrl, ENT_QUOTES);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f0f2f5;padding:30px;margin:0}
  .w{max-width:600px;margin:auto}
  .hdr{background:#28a745;color:#fff;padding:22px 28px;border-radius:8px 8px 0 0}
  .hdr h1{font-size:19px;margin:0}
  .bdy{background:#fff;padding:28px;border:1px solid #dde3ec;border-top:none;color:#444;line-height:1.6}
  .box{background:#f7fff9;border-left:4px solid #28a745;padding:12px 16px;margin:16px 0;border-radius:0 6px 6px 0}
  .btn{display:inline-block;padding:14px 34px;background:#2d6cdf;color:#fff;
       text-decoration:none;border-radius:6px;font-weight:700;font-size:15px;margin-top:20px}
  .ftr{background:#f7f9ff;padding:14px 28px;border:1px solid #dde3ec;border-top:none;
       border-radius:0 0 8px 8px;font-size:12px;color:#888}
</style></head><body>
<div class="w">
  <div class="hdr"><h1>&#9989; Solicitud Aprobada &mdash; Alta de Correo</h1></div>
  <div class="bdy">
    <p>El directivo ha <strong>aprobado</strong> la solicitud del <strong>Ticket #$ticketId</strong>.</p>
    <div class="box">Complete el formulario para registrar el correo institucional creado.</div>
    <p>Haga clic en el bot&oacute;n para acceder al formulario.
       <strong>No es necesario iniciar sesi&oacute;n en GLPI.</strong></p>
    <a href="$safeUrl" class="btn">&#128394; Completar formulario</a>
    <p style="margin-top:20px;font-size:12px;color:#888">O copie este enlace en su navegador:<br>$safeUrl</p>
  </div>
  <div class="ftr">Generado autom&aacute;ticamente &mdash; Plugin Solicitud &mdash; GLPI</div>
</div>
</body>
</html>
HTML;

    plugin_solicitud_send_email(
        $computosEmail,
        "Alta de Correo Institucional — Ticket #$ticketId: APROBADA",
        $html
    );
}

// ─── Agregar seguimiento (followup) al ticket ─────────────────────────────────

/**
 * Inserta un seguimiento interno en el ticket.
 *
 * @param int    $ticketId
 * @param string $message  Texto del seguimiento.
 */
function plugin_solicitud_add_followup(int $ticketId, string $message): void
{
    /** @var \DBmysql $DB */
    global $DB;

    // Se usa $DB->insert() directamente para evitar el chequeo de permisos de
    // ITILFollowup::add(), que requiere un usuario autenticado en sesión.
    $now = date('Y-m-d H:i:s');

    $DB->insert('glpi_itilfollowups', [
        'itemtype'        => 'Ticket',
        'items_id'        => $ticketId,
        'users_id'        => 0,   // 0 = acción automática del sistema
        'content'         => $message,
        'is_private'      => 0,
        'requesttypes_id' => 0,
        'date'            => $now,
        'date_creation'   => $now,
        'date_mod'        => $now,
    ]);
}
