<?php
/**
 * front/approval.php
 * Endpoint público: procesa la aprobación o rechazo del director vía link de email.
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

$pdo->prepare(
    'UPDATE glpi_plugin_solicitud_tokens
     SET status = ?, date_action = ?
     WHERE token = ?'
)->execute([$newTokenStatus, $now, $token]);

// ── 8. Cambiar estado del ticket ──────────────────────────────────────────────
// approve → el estado final (5 Resuelto) lo gestiona _auto_generate_institutional_email
// reject  → 6 (CLOSED / "Cerrado")
if ($action === 'reject') {
    $pdo->prepare(
        'UPDATE glpi_tickets SET status = 6, date_mod = ? WHERE id = ?'
    )->execute([$now, $ticketId]);
}

// Prefija el título del ticket al aprobar
if ($action === 'approve') {
    $currentName = $pdo->prepare('SELECT name FROM glpi_tickets WHERE id = ? LIMIT 1');
    $currentName->execute([$ticketId]);
    $ticketRow = $currentName->fetch();
    if ($ticketRow && strpos($ticketRow['name'], '[Aprobada por Director]') === false) {
        $newName = '[Aprobada por Director] ' . $ticketRow['name'];
        $pdo->prepare('UPDATE glpi_tickets SET name = ? WHERE id = ?')
            ->execute([$newName, $ticketId]);
    }
}

// ── 9. Insertar seguimiento en el ticket ──────────────────────────────────────
$label   = ($action === 'approve') ? 'APROBADA' : 'RECHAZADA';
$content = ($action === 'approve')
    ? "Solicitud aprobada por el director vía email (plugin Solicitud — acción automática)."
    : "Solicitud RECHAZADA por el director vía email (plugin Solicitud — acción automática).";

$pdo->prepare(
    'INSERT INTO glpi_itilfollowups
        (itemtype, items_id, users_id, content, is_private, requesttypes_id, date, date_creation, date_mod)
     VALUES
        (?, ?, 0, ?, 0, 0, ?, ?, ?)'
)->execute(['Ticket', $ticketId, $content, $now, $now, $now]);

// ── 10. Acciones tras la decisión ─────────────────────────────────────────────────
if ($action === 'approve') {
    // Aprobado: generar correo institucional automáticamente y notificar al solicitante
    _auto_generate_institutional_email($pdo, $glpiRoot, $ticketId, $now);
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
 * Genera automáticamente el correo institucional del solicitante tras la aprobación
 * del director, usando la inicial del nombre + apellido (incrementando letras si ya existe).
 * Registra el resultado en el ticket y notifica al solicitante.
 */
function _auto_generate_institutional_email(PDO $pdo, string $glpiRoot, int $ticketId, string $now): void
{
    // ── 1. Obtener datos del solicitante ──────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT u.firstname, u.realname, u.email
         FROM glpi_tickets_users tu
         JOIN glpi_users u ON u.id = tu.users_id
         WHERE tu.tickets_id = ? AND tu.type = 1
         LIMIT 1'
    );
    $stmt->execute([$ticketId]);
    $user = $stmt->fetch();

    if (!$user) {
        error_log("[plugin_solicitud] Ticket #$ticketId: no se encontró solicitante para generar email.");
        return;
    }

    $firstname = trim($user['firstname'] ?? '');
    $lastname  = trim($user['realname']  ?? '');
    $toEmail   = trim($user['email']     ?? '');

    if ($firstname === '' || $lastname === '') {
        error_log("[plugin_solicitud] Ticket #$ticketId: solicitante sin nombre/apellido completo.");
        return;
    }

    // ── 2. Obtener dominio del correo institucional desde la config ───────────
    $cfg = $pdo->query(
        'SELECT computos_email FROM glpi_plugin_solicitud_configs LIMIT 1'
    )->fetch();

    $dominio = 'institucional.com';
    if (!empty($cfg['computos_email']) && str_contains($cfg['computos_email'], '@')) {
        $dominio = explode('@', $cfg['computos_email'])[1];
    }

    // ── 3. Normalizar: sin tildes, lowercase, solo caracteres alfanuméricos ───
    $normalize = static function (string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        $map = [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
            'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
            'â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u',
            'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
            'ñ'=>'n','ç'=>'c',
        ];
        $s = strtr($s, $map);
        return preg_replace('/[^a-z0-9]/', '', $s);
    };

    $normFirst = $normalize($firstname);
    $normLast  = $normalize($lastname);

    // ── 4. Generar nombre de usuario único (1.ª letra, 2.ª letra, ...) ────────
    $username = null;
    $maxLen   = mb_strlen($normFirst, 'UTF-8');

    for ($n = 1; $n <= $maxLen; $n++) {
        $candidate = mb_substr($normFirst, 0, $n, 'UTF-8') . $normLast;
        $check = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM glpi_users WHERE email = ? OR name = ?'
        );
        $check->execute(["$candidate@$dominio", $candidate]);
        $row = $check->fetch();
        if ((int)($row['cnt'] ?? 1) === 0) {
            $username = $candidate;
            break;
        }
    }

    // Si se agotaron las iniciales, añadir sufijo numérico
    if ($username === null) {
        $base = $normFirst . $normLast;
        for ($n = 1; $n < 100; $n++) {
            $candidate = $base . $n;
            $check = $pdo->prepare(
                'SELECT COUNT(*) AS cnt FROM glpi_users WHERE email = ? OR name = ?'
            );
            $check->execute(["$candidate@$dominio", $candidate]);
            $row = $check->fetch();
            if ((int)($row['cnt'] ?? 1) === 0) {
                $username = $candidate;
                break;
            }
        }
        $username = $username ?? ($base . '_' . substr($now, 0, 10));
    }

    $fullEmail = "$username@$dominio";

    // ── 5. Generar contraseña temporal aleatoria ──────────────────────────────
    $chars    = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#';
    $password = '';
    for ($i = 0; $i < 12; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }

    // ── 6. Agregar followup privado con los datos del correo ──────────────────
    $followupContent =
        "Correo institucional generado automáticamente:\n\n" .
        "  • Correo : $fullEmail\n" .
        "  • Contraseña: $password\n\n" .
        "Acción automática ejecutada tras la aprobación del director (plugin Solicitud).";

    $pdo->prepare(
        'INSERT INTO glpi_itilfollowups
            (itemtype, items_id, users_id, content, is_private, requesttypes_id, date, date_creation, date_mod)
         VALUES (?, ?, 0, ?, 1, 0, ?, ?, ?)'
    )->execute(['Ticket', $ticketId, $followupContent, $now, $now, $now]);

    // ── 7. Agregar solución técnica ───────────────────────────────────────────
    $solutionContent =
        "Correo institucional creado exitosamente.\n\n" .
        "  • Dirección : $fullEmail\n" .
        "  • Contraseña entregada al solicitante.\n\n" .
        "Pendiente de confirmación por el solicitante.";

    $existsSol = $pdo->prepare(
        'SELECT id FROM glpi_itilsolutions WHERE itemtype = ? AND items_id = ? LIMIT 1'
    );
    $existsSol->execute(['Ticket', $ticketId]);
    if (!$existsSol->fetch()) {
        $pdo->prepare(
            'INSERT INTO glpi_itilsolutions
                (itemtype, items_id, users_id, content, solutiontypes_id, status, date_creation, date_mod, date_approval)
             VALUES (?, ?, 0, ?, 0, 3, ?, ?, ?)'
        )->execute(['Ticket', $ticketId, $solutionContent, $now, $now, $now]);
    }

    // ── 8. Pasar ticket a estado Resuelto (5) ─────────────────────────────────
    $pdo->prepare(
        'UPDATE glpi_tickets SET status = 5, date_mod = ?, solvedate = ? WHERE id = ?'
    )->execute([$now, $now, $ticketId]);

    // ── 9. Notificar al solicitante por email ─────────────────────────────────
    if ($toEmail !== '') {
        _notify_requester_auto($glpiRoot, $ticketId, $toEmail, $fullEmail, $password, $now);
    }
}

/**
 * Envía email al solicitante con los datos del correo institucional generado automáticamente.
 */
function _notify_requester_auto(
    string $glpiRoot,
    int    $ticketId,
    string $toEmail,
    string $fullEmail,
    string $password,
    string $now
): void {
    try {
        require_once $glpiRoot . '/vendor/autoload.php';
        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            'sandbox.smtp.mailtrap.io', 2525, false
        );
        $transport->setUsername('cffec8d0d2e053');
        $transport->setPassword('6d606c0f591c23');
        $mailer = new \Symfony\Component\Mailer\Mailer($transport);

        $safeEmail = htmlspecialchars($fullEmail, ENT_QUOTES);

        $html = "
<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
<style>
  body{font-family:Arial,sans-serif;background:#f0f2f5;padding:30px;margin:0}
  .w{max-width:600px;margin:auto}
  .hdr{background:#2d6cdf;color:#fff;padding:22px 28px;border-radius:8px 8px 0 0}
  .hdr h1{font-size:19px;margin:0}
  .bdy{background:#fff;padding:28px;border:1px solid #dde3ec;border-top:none;color:#444;line-height:1.6}
  .data-box{background:#f7f9ff;border-left:4px solid #2d6cdf;padding:14px 20px;
             margin:18px 0;border-radius:0 6px 6px 0;font-size:15px}
  .data-box dt{font-weight:700;color:#2d6cdf;margin-top:8px}
  .data-box dd{margin:2px 0 0 0;color:#333}
  .warn{font-size:12px;color:#888;margin-top:18px;border-top:1px solid #eee;padding-top:12px}
  .ftr{background:#f7f9ff;padding:14px 28px;border:1px solid #dde3ec;border-top:none;
       border-radius:0 0 8px 8px;font-size:12px;color:#888}
</style></head><body>
<div class='w'>
  <div class='hdr'><h1>&#9993; Tu correo institucional fue creado</h1></div>
  <div class='bdy'>
    <p>Tu solicitud del <strong>Ticket #{$ticketId}</strong> ha sido aprobada y procesada.<br>
       A continuaci&oacute;n encontrar&aacute;s los datos de acceso a tu nuevo correo institucional:</p>
    <dl class='data-box'>
      <dt>Direcci&oacute;n de correo</dt>
      <dd>{$safeEmail}</dd>
      <dt>Contrase&ntilde;a inicial</dt>
      <dd>{$password}</dd>
    </dl>
    <p>Te recomendamos cambiar tu contrase&ntilde;a la primera vez que ingreses.</p>
    <p class='warn'>Este mensaje es confidencial. No lo reenv&iacute;es.<br>
       Si no solicitaste un correo institucional, comunicate con el &aacute;rea de Sistemas.</p>
  </div>
  <div class='ftr'>Generado autom&aacute;ticamente &mdash; {$now}</div>
</div></body></html>";

        $emailMsg = (new \Symfony\Component\Mime\Email())
            ->from('noreply@glpi.local')
            ->to($toEmail)
            ->subject("Tu correo institucional fue creado — Ticket #$ticketId")
            ->html($html)
            ->text("Tu correo institucional: $fullEmail | Contraseña: $password");

        $mailer->send($emailMsg);

    } catch (\Throwable $e) {
        error_log('[plugin_solicitud] Error notificando solicitante (auto): ' . $e->getMessage());
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

        $html = "<p>El director ha <strong>$label</strong> la solicitud del "
              . "<strong>Ticket #$ticketId</strong>.</p>"
              . "<p>Por favor, proceda según el resultado.</p>"
              . "<small>Generado automáticamente — $now</small>";

        $email = (new \Symfony\Component\Mime\Email())
            ->from('noreply@glpi.local')
            ->to($itEmail)
            ->subject("Solicitud Ticket #$ticketId: $label")
            ->html($html)
            ->text("El director ha $label la solicitud del Ticket #$ticketId.");

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
            $subtitle = 'El ticket ha pasado a estado &ldquo;Solicitud aprobada por el director&rdquo;.';
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
        echo "    <p>Las notificaciones correspondientes han sido enviadas autom&aacute;ticamente. "
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
