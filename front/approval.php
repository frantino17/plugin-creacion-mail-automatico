<?php
/**
 * front/approval.php
 * Endpoint público: procesa la aprobación o rechazo del directivo vía link de email.
 *
 * GET /plugins/solicitud/front/approval.php?token=XXXX&action=approve|reject
 *
 * GLPI 11.x: NO carga inc/includes.php porque Symfony intercepta el request,
 * verifica la sesión y llama exit() antes de que podamos mostrar nuestra página.
 * En su lugar, conectamos directamente a MySQL usando las credenciales del
 * archivo config/config_db.php de GLPI.
 */

// ── 1. Leer y sanear parámetros GET ──────────────────────────────────────────
$rawToken  = isset($_GET['token'])  ? trim($_GET['token'])  : '';
$rawAction = isset($_GET['action']) ? trim($_GET['action']) : '';

$token  = preg_replace('/[^a-f0-9]/i', '', $rawToken);
$action = in_array($rawAction, ['approve', 'reject'], true) ? $rawAction : '';

// ── 2. Leer credenciales de BD de GLPI parseando config_db.php con regex ──────
// Ruta: front/ → solicitud/ → plugins/ → <glpi-root>
$glpiRoot   = realpath(dirname(__DIR__, 3));
$configFile = $glpiRoot . '/config/config_db.php';

if (!$glpiRoot || !file_exists($configFile)) {
    _render_page('error', 'Error de configuración: no se encontró la base de datos de GLPI.', 0);
    exit;
}

// Parseamos el archivo como texto para extraer las propiedades,
// SIN ejecutarlo (incluirlo fallaría porque DB extiende DBmysql, clase de GLPI).
$cfgContent = file_get_contents($configFile);

$host = 'localhost';
$user = '';
$pass = '';
$name = '';

foreach ([
    'dbhost'     => &$host,
    'dbuser'     => &$user,
    'dbpassword' => &$pass,
    'dbdefault'  => &$name,
] as $prop => &$var) {
    if (preg_match('/\$' . $prop . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $cfgContent, $m)) {
        $var = $m[1];
    }
}
unset($var);

// ── 3. Conectar a MySQL via PDO ───────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (\Throwable $e) {
    _render_page('error', 'No se pudo conectar a la base de datos: ' . $e->getMessage(), 0);
    exit;
}

// ── 4. Validar parámetros básicos ─────────────────────────────────────────────
if ($token === '' || $action === '') {
    _render_page('error', 'Petición inválida: token o acción ausentes.', 0);
    exit;
}

// ── 4. Buscar token en BD ─────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT id, tickets_id, status, expires_at
     FROM glpi_plugin_solicitud_tokens
     WHERE token = ?
     LIMIT 1'
);
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) {
    _render_page('error', 'Token no encontrado o inválido.', 0);
    exit;
}

$ticketId = (int) $row['tickets_id'];

// ── 5. Validar expiración ─────────────────────────────────────────────────────
if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
    _render_page('expired', 'Este enlace ha expirado. Contacte al área IT para generar uno nuevo.', $ticketId);
    exit;
}

// ── 6. Verificar que no haya sido procesado ya ────────────────────────────────
if ($row['status'] !== 'pending') {
    $ya = ($row['status'] === 'approved') ? 'aprobada' : 'rechazada';
    _render_page($row['status'], "Esta solicitud ya fue $ya con anterioridad.", $ticketId);
    exit;
}

// ── 7. Actualizar estado del token ────────────────────────────────────────────
$newTokenStatus = ($action === 'approve') ? 'approved' : 'rejected';
$now = date('Y-m-d H:i:s');

// Al aprobar: generar form_token para el formulario de Cómputos
$formToken = null;
if ($action === 'approve') {
    $formToken = bin2hex(random_bytes(32)); // 64 chars hex
    $pdo->prepare(
        'UPDATE glpi_plugin_solicitud_tokens
         SET status = ?, date_action = ?, form_token = ?
         WHERE token = ?'
    )->execute([$newTokenStatus, $now, $formToken, $token]);
} else {
    $pdo->prepare(
        'UPDATE glpi_plugin_solicitud_tokens
         SET status = ?, date_action = ?
         WHERE token = ?'
    )->execute([$newTokenStatus, $now, $token]);
}

// ── 8. Cambiar estado del ticket ──────────────────────────────────────────────
// approve → 3 (PLANNED / "En curso planificado")
// reject  → 6 (CLOSED / "Cerrado")
$newTicketStatus = ($action === 'approve') ? 3 : 6;

$pdo->prepare(
    'UPDATE glpi_tickets SET status = ?, date_mod = ? WHERE id = ?'
)->execute([$newTicketStatus, $now, $ticketId]);

// ── 9. Insertar seguimiento en el ticket ──────────────────────────────────────
$label   = ($action === 'approve') ? 'APROBADA' : 'RECHAZADA';
$content = "Solicitud $label por el directivo vía email (plugin Solicitud — acción automática).";

$pdo->prepare(
    'INSERT INTO glpi_itilfollowups
        (itemtype, items_id, users_id, content, is_private, requesttypes_id, date, date_creation, date_mod)
     VALUES
        (?, ?, 0, ?, 0, 0, ?, ?, ?)'
)->execute(['Ticket', $ticketId, $content, $now, $now, $now]);

// ── 10. Notificar según la decisión ────────────────────────────────────────────────
if ($action === 'approve' && $formToken !== null) {
    // Aprobado: enviar email a Cómputos con link al formulario
    _send_computos_notification($pdo, $glpiRoot, $ticketId, $formToken, $now);
} else {
    // Rechazado: notificar al área IT
    _send_it_notification($pdo, $glpiRoot, $ticketId, $action, $now);
}

// ── 11. Renderizar página de confirmación ─────────────────────────────────────
$message  = ($action === 'approve')
    ? 'La solicitud ha sido aprobada correctamente.'
    : 'La solicitud ha sido rechazada correctamente.';
$decision = $newTokenStatus;

_render_page($decision, $message, $ticketId);


// ═════════════════════════════════════════════════════════════════════════════
// Funciones internas
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Envía email al área de Cómputos con link al formulario de alta de correo.
 */
function _send_computos_notification(PDO $pdo, string $glpiRoot, int $ticketId, string $formToken, string $now): void
{
    try {
        $cfg = $pdo->query(
            'SELECT computos_email, glpi_base_url FROM glpi_plugin_solicitud_configs LIMIT 1'
        )->fetch();

        $computosEmail = $cfg['computos_email'] ?? '';
        $baseUrl       = rtrim($cfg['glpi_base_url'] ?? '', '/');
        if ($computosEmail === '') return;

        $formUrl = "$baseUrl/plugins/solicitud/front/form.php?form_token=$formToken";
        $safeUrl = htmlspecialchars($formUrl, ENT_QUOTES);

        require_once $glpiRoot . '/vendor/autoload.php';
        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            'sandbox.smtp.mailtrap.io', 2525, false
        );
        $transport->setUsername('cffec8d0d2e053');
        $transport->setPassword('6d606c0f591c23');
        $mailer = new \Symfony\Component\Mailer\Mailer($transport);

        $html = "
<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
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
<div class='w'>
  <div class='hdr'><h1>&#9989; Solicitud Aprobada &mdash; Alta de Correo</h1></div>
  <div class='bdy'>
    <p>El directivo ha <strong>aprobado</strong> la solicitud del <strong>Ticket #{$ticketId}</strong>.</p>
    <div class='box'>Por favor complete el formulario para registrar el correo institucional creado.</div>
    <p>Haga clic en el bot&oacute;n para acceder al formulario. <strong>No es necesario iniciar sesi&oacute;n en GLPI.</strong></p>
    <a href='{$safeUrl}' class='btn'>&#128394; Completar formulario</a>
    <p style='margin-top:20px;font-size:12px;color:#888'>O copie este enlace:<br>{$safeUrl}</p>
  </div>
  <div class='ftr'>Generado autom&aacute;ticamente &mdash; {$now}</div>
</div></body></html>";

        $email = (new \Symfony\Component\Mime\Email())
            ->from('noreply@glpi.local')
            ->to($computosEmail)
            ->subject("Alta de Correo Institucional \u2014 Ticket #$ticketId: APROBADA")
            ->html($html)
            ->text("Solicitud aprobada. Complete el formulario en: $formUrl");

        $mailer->send($email);

    } catch (\Throwable $e) {
        error_log('[plugin_solicitud] Error enviando email Computos: ' . $e->getMessage());
    }
}

/**
 * Envía email de notificación al área IT usando Symfony Mailer del vendor de GLPI.
 */
function _send_it_notification(PDO $pdo, string $glpiRoot, int $ticketId, string $action, string $now): void
{
    try {
        $cfg = $pdo->query(
            'SELECT it_email FROM glpi_plugin_solicitud_configs LIMIT 1'
        )->fetch();

        $itEmail = $cfg['it_email'] ?? '';
        if ($itEmail === '') return;

        $label = ($action === 'approve') ? 'APROBADA' : 'RECHAZADA';

        require_once $glpiRoot . '/vendor/autoload.php';
        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            'sandbox.smtp.mailtrap.io', 2525, false
        );
        $transport->setUsername('cffec8d0d2e053');
        $transport->setPassword('6d606c0f591c23');
        $mailer = new \Symfony\Component\Mailer\Mailer($transport);

        $html = "<p>El directivo ha <strong>$label</strong> la solicitud del "
              . "<strong>Ticket #$ticketId</strong>.</p>"
              . "<p>Por favor, proceda según el resultado.</p>"
              . "<small>Generado automáticamente — $now</small>";

        $email = (new \Symfony\Component\Mime\Email())
            ->from('noreply@glpi.local')
            ->to($itEmail)
            ->subject("Solicitud Ticket #$ticketId: $label")
            ->html($html)
            ->text("El directivo ha $label la solicitud del Ticket #$ticketId.");

        $mailer->send($email);

    } catch (\Throwable $e) {
        error_log('[plugin_solicitud] Error enviando email IT: ' . $e->getMessage());
    }
}

/**
 * Renderiza la página HTML de confirmación y termina la ejecución.
 *
 * @param string $decision  'approved' | 'rejected' | 'expired' | 'error'
 * @param string $message   Texto principal descriptivo.
 * @param int    $ticketId  0 si no aplica.
 */
function _render_page(string $decision, string $message, int $ticketId): void
{
    header('Content-Type: text/html; charset=UTF-8');

    switch ($decision) {
        case 'approved':
            $icon     = '&#10004;';
            $label    = 'Solicitud Aprobada';
            $accent   = '#28a745';
            $subtitle = 'El ticket ha pasado a estado &ldquo;En curso planificado&rdquo;.';
            break;
        case 'rejected':
            $icon     = '&#10008;';
            $label    = 'Solicitud Rechazada';
            $accent   = '#dc3545';
            $subtitle = 'El ticket ha pasado a estado &ldquo;Cerrado&rdquo;.';
            break;
        case 'expired':
            $icon     = '&#9200;';
            $label    = 'Enlace expirado';
            $accent   = '#fd7e14';
            $subtitle = 'El plazo para responder a esta solicitud ha vencido.';
            break;
        default:
            $icon     = '&#9888;';
            $label    = 'Error';
            $accent   = '#ffc107';
            $subtitle = '';
            break;
    }

    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $isSuccess   = in_array($decision, ['approved', 'rejected'], true);

    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$label} — GLPI</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0 }
  body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f0f2f5;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0,0,0,.12);
    max-width: 500px;
    width: 100%;
    overflow: hidden;
  }
  .card-header {
    background: {$accent};
    color: #fff;
    padding: 30px 32px 24px;
    text-align: center;
  }
  .card-header .icon { font-size: 52px; line-height: 1; margin-bottom: 10px }
  .card-header h1   { font-size: 22px; font-weight: 700 }
  .card-body  { padding: 28px 32px; color: #444; line-height: 1.6 }
  .card-body p { margin-bottom: 12px }
  .ticket-ref {
    display: inline-block;
    background: #f0f2f5;
    border-radius: 4px;
    padding: 6px 14px;
    font-weight: 700;
    color: #2d6cdf;
    margin: 8px 0 16px;
  }
  .card-footer {
    padding: 16px 32px 24px;
    font-size: 12px;
    color: #999;
    text-align: center;
    border-top: 1px solid #eee;
  }
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <div class="icon">{$icon}</div>
    <h1>{$label}</h1>
  </div>
  <div class="card-body">
    <p>{$safeMessage}</p>
HTML;

    if ($subtitle !== '') {
        echo "    <p style=\"color:#666;font-style:italic\">$subtitle</p>\n";
    }
    if ($ticketId > 0) {
        echo "    <div>Ticket de referencia:</div>\n";
        echo "    <span class=\"ticket-ref\">#$ticketId</span>\n";
    }
    if ($isSuccess) {
        echo "    <p>El &aacute;rea de IT ha sido notificada autom&aacute;ticamente. "
           . "No es necesario realizar ninguna acci&oacute;n adicional.</p>\n";
    }

    echo <<<HTML
  </div>
  <div class="card-footer">
    Este portal es gestionado por el sistema GLPI de su organización.<br>
    No responda a este mensaje.
  </div>
</div>
</body>
</html>
HTML;
}
