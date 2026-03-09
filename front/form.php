<?php
/**
 * front/form.php
 * Formulario para que el área de Cómputos ingrese los datos
 * del correo institucional creado.
 *
 * GET  → muestra el formulario
 * POST → procesa los datos, guarda followup + solution, cambia estado del ticket
 *
 * No requiere sesión de GLPI: la autenticación es por form_token.
 */

// ── 1. Leer y sanear parámetros ───────────────────────────────────────────────
$formToken = preg_replace('/[^a-f0-9]/i', '', trim($_REQUEST['form_token'] ?? ''));
$isPost    = ($_SERVER['REQUEST_METHOD'] === 'POST');

// ── 2. Conectar a MySQL via PDO (igual que approval.php) ──────────────────────
$glpiRoot   = realpath(dirname(__DIR__, 3));
$configFile = $glpiRoot . '/config/config_db.php';

if (!$glpiRoot || !file_exists($configFile)) {
    _form_page_error('Error de configuración: no se encontró la base de datos de GLPI.');
}

$cfgContent = file_get_contents($configFile);
$host = 'localhost'; $user = ''; $pass = ''; $name = '';
foreach (['dbhost'=>&$host,'dbuser'=>&$user,'dbpassword'=>&$pass,'dbdefault'=>&$name] as $p => &$v) {
    if (preg_match('/\$'.$p.'\s*=\s*[\'"]([^\'"]*)[\'"]/', $cfgContent, $m)) $v = $m[1];
}
unset($v);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (\Throwable $e) {
    _form_page_error('No se pudo conectar a la base de datos.');
}

// ── 3. Validar form_token ─────────────────────────────────────────────────────
if ($formToken === '') {
    _form_page_error('Enlace inválido: token ausente.');
}

$row = $pdo->prepare(
    'SELECT id, tickets_id, status, form_token
     FROM glpi_plugin_solicitud_tokens
     WHERE form_token = ?
     LIMIT 1'
);
$row->execute([$formToken]);
$tokenRow = $row->fetch();

if (!$tokenRow) {
    _form_page_error('Enlace inválido o expirado.');
}

if ($tokenRow['status'] !== 'approved') {
    _form_page_error('Esta solicitud no ha sido aprobada o el formulario ya fue completado.');
}

$ticketId = (int) $tokenRow['tickets_id'];

// ── 4. Obtener título del ticket ──────────────────────────────────────────────
$tRow = $pdo->prepare('SELECT name FROM glpi_tickets WHERE id = ? LIMIT 1');
$tRow->execute([$ticketId]);
$ticket = $tRow->fetch();
$ticketTitle = $ticket['name'] ?? "Ticket #$ticketId";

// ── 5. Procesar POST ──────────────────────────────────────────────────────────
$errors   = [];
$success  = false;

if ($isPost) {
    $mailCreado = trim($_POST['mail_creado'] ?? '');
    $password   = trim($_POST['password']    ?? '');
    $dominio    = trim($_POST['dominio']      ?? 'institucional.com');

    // Validaciones básicas
    if ($mailCreado === '') $errors[] = 'El campo "Correo creado" es obligatorio.';
    if ($password   === '') $errors[] = 'El campo "Contraseña" es obligatorio.';
    if ($dominio    === '') $dominio  = 'institucional.com';

    if (empty($errors)) {
        $now = date('Y-m-d H:i:s');

        // ── 5a. Marcar token como 'form_sent' para evitar reenvíos ────────────
        $pdo->prepare(
            "UPDATE glpi_plugin_solicitud_tokens SET status = 'form_sent' WHERE form_token = ?"
        )->execute([$formToken]);

        // ── 5b. Followup con los datos ingresados ─────────────────────────────
        $followupContent =
            "Correo institucional creado por el área de Cómputos:\n\n" .
            "  • Correo: $mailCreado\n" .
            "  • Dominio: $dominio\n" .
            "  • Contraseña: $password\n\n" .
            "Acción realizada a través del formulario del plugin Solicitud.";

        $pdo->prepare(
            'INSERT INTO glpi_itilfollowups
                (itemtype, items_id, users_id, content, is_private, requesttypes_id, date, date_creation, date_mod)
             VALUES (?, ?, 0, ?, 1, 0, ?, ?, ?)'
        )->execute(['Ticket', $ticketId, $followupContent, $now, $now, $now]);

        // ── 5c. Solution (cierre técnico del ticket) ──────────────────────────
        $solutionContent =
            "Correo institucional creado exitosamente.\n\n" .
            "  • Dirección: {$mailCreado}@{$dominio}\n" .
            "  • Contraseña entregada al solicitante.\n\n" .
            "Pendiente de confirmación por el solicitante.";

        // Verificar si ya existe una solución para este ticket
        $existsSol = $pdo->prepare(
            'SELECT id FROM glpi_itilsolutions WHERE itemtype=? AND items_id=? LIMIT 1'
        );
        $existsSol->execute(['Ticket', $ticketId]);

        if (!$existsSol->fetch()) {
            $pdo->prepare(
                'INSERT INTO glpi_itilsolutions
                    (itemtype, items_id, users_id, content, solutiontypes_id, status, date_creation, date_mod, date_approval)
                 VALUES (?, ?, 0, ?, 0, 3, ?, ?, ?)'
                // status=3 en glpi_itilsolutions = "Aprobado"
            )->execute(['Ticket', $ticketId, $solutionContent, $now, $now, $now]);
        }

        // ── 5d. Cambiar estado del ticket a "Resuelto" (5) ─────────────────
        $pdo->prepare(
            'UPDATE glpi_tickets SET status = 5, date_mod = ?, solvedate = ? WHERE id = ?'
        )->execute([$now, $now, $ticketId]);

        // ── 5e. Notificar al solicitante con los datos del correo creado ──────
        _notify_requester($pdo, $glpiRoot, $ticketId, $mailCreado, $dominio, $password, $now);

        $success = true;
    }
}

// ── 6. Renderizar ─────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $success ? 'Formulario enviado' : 'Formulario de Alta de Correo' ?> — GLPI</title>
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
    max-width: 540px;
    width: 100%;
    overflow: hidden;
  }
  .card-header {
    background: #2d6cdf;
    color: #fff;
    padding: 24px 32px;
  }
  .card-header h1 { font-size: 20px; font-weight: 700; margin-bottom: 4px }
  .card-header p  { font-size: 13px; opacity: .85 }
  .card-body  { padding: 28px 32px }
  .card-footer {
    padding: 14px 32px;
    font-size: 12px;
    color: #999;
    text-align: center;
    border-top: 1px solid #eee;
  }
  .form-group { margin-bottom: 18px }
  label { display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 6px }
  input[type=text], input[type=password], input[type=email] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #cdd3dc;
    border-radius: 6px;
    font-size: 14px;
    color: #333;
    outline: none;
    transition: border .2s;
  }
  input:focus { border-color: #2d6cdf }
  .hint { font-size: 11px; color: #888; margin-top: 4px }
  .btn-submit {
    width: 100%;
    padding: 12px;
    background: #2d6cdf;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 8px;
  }
  .btn-submit:hover { background: #1a56c4 }
  .errors {
    background: #fff3f3;
    border: 1px solid #f5c6cb;
    border-radius: 6px;
    padding: 12px 16px;
    margin-bottom: 18px;
    color: #721c24;
    font-size: 13px;
  }
  .errors li { margin-left: 16px }
  /* Página de éxito */
  .success-header { background: #28a745 }
  .success-icon { font-size: 52px; text-align: center; margin-bottom: 12px }
  .success-msg { text-align: center; color: #444; line-height: 1.6 }
  .ticket-ref {
    display: inline-block;
    background: #f0f2f5;
    border-radius: 4px;
    padding: 6px 14px;
    font-weight: 700;
    color: #2d6cdf;
    margin: 8px 0 16px;
  }
</style>
</head>
<body>
<div class="card">

<?php if ($success): ?>
  <div class="card-header success-header">
    <div class="success-icon">&#10004;</div>
    <h1 style="text-align:center">Formulario enviado</h1>
  </div>
  <div class="card-body success-msg">
    <p>Los datos del correo institucional han sido registrados correctamente en el ticket.</p>
    <br>
    <div>Ticket de referencia:</div>
    <span class="ticket-ref">#<?= $ticketId ?></span>
    <p>El ticket ha pasado a estado <strong>Resuelto</strong>.<br>
       El solicitante podrá cerrarlo o reabrirlo desde GLPI.</p>
  </div>

<?php else: ?>
  <div class="card-header">
    <h1>Alta de Correo Institucional</h1>
    <p>Ticket #<?= $ticketId ?> — <?= htmlspecialchars($ticketTitle, ENT_QUOTES) ?></p>
  </div>
  <div class="card-body">

    <?php if (!empty($errors)): ?>
      <div class="errors">
        <strong>Por favor corrija los siguientes errores:</strong>
        <ul>
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e, ENT_QUOTES) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" action="?form_token=<?= htmlspecialchars($formToken, ENT_QUOTES) ?>">
      <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken, ENT_QUOTES) ?>">

      <div class="form-group">
        <label for="mail_creado">Correo institucional creado *</label>
        <input type="text" id="mail_creado" name="mail_creado"
               placeholder="usuario"
               value="<?= htmlspecialchars($_POST['mail_creado'] ?? '', ENT_QUOTES) ?>"
               required>
        <div class="hint">Solo el nombre de usuario, sin el dominio.</div>
      </div>

      <div class="form-group">
        <label for="dominio">Dominio *</label>
        <input type="text" id="dominio" name="dominio"
               placeholder="institucional.com"
               value="<?= htmlspecialchars($_POST['dominio'] ?? 'institucional.com', ENT_QUOTES) ?>"
               required>
      </div>

      <div class="form-group">
        <label for="password">Contraseña generada *</label>
        <input type="text" id="password" name="password"
               placeholder="Contraseña inicial"
               value="<?= htmlspecialchars($_POST['password'] ?? '', ENT_QUOTES) ?>"
               required>
        <div class="hint">Esta información quedará registrada como nota privada en el ticket.</div>
      </div>

      <button type="submit" class="btn-submit">Registrar y cerrar ticket</button>
    </form>

  </div>
<?php endif; ?>

  <div class="card-footer">
    Portal gestionado por el sistema GLPI de su organización.
  </div>
</div>
</body>
</html>
<?php

// ═══════════════════════════════════════════════════════════
// Helper: enviar email al solicitante con los datos del correo
// ═══════════════════════════════════════════════════════════

/**
 * Busca el email del solicitante del ticket y le envía los datos
 * del correo institucional creado, usando Mailtrap como SMTP.
 */
function _notify_requester(
    PDO    $pdo,
    string $glpiRoot,
    int    $ticketId,
    string $mailCreado,
    string $dominio,
    string $password,
    string $now
): void {
    try {
        // Obtener email del solicitante (type=1 en glpi_tickets_users)
        $stmt = $pdo->prepare(
            'SELECT u.email
             FROM glpi_tickets_users tu
             JOIN glpi_users u ON u.id = tu.users_id
             WHERE tu.tickets_id = ? AND tu.type = 1 AND u.email != \'\'
             LIMIT 1'
        );
        $stmt->execute([$ticketId]);
        $requester = $stmt->fetch();

        $toEmail = $requester['email'] ?? '';
        if ($toEmail === '') return; // sin destinatario, no enviar

        require_once $glpiRoot . '/vendor/autoload.php';
        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            'sandbox.smtp.mailtrap.io', 2525, false
        );
        $transport->setUsername('c728d26433c791');
        $transport->setPassword('807c73cb9509b2');
        $mailer = new \Symfony\Component\Mailer\Mailer($transport);

        $fullAddress = htmlspecialchars("{$mailCreado}@{$dominio}", ENT_QUOTES);

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
    <p>Tu solicitud del <strong>Ticket #{$ticketId}</strong> ha sido procesada.<br>
       A continuaci&oacute;n encontrar&aacute;s los datos de acceso a tu nuevo correo institucional:</p>
    <dl class='data-box'>
      <dt>Direcci&oacute;n de correo</dt>
      <dd>{$fullAddress}</dd>
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
            ->text("Tu correo institucional: {$mailCreado}@{$dominio} | Contraseña: $password");

        $mailer->send($emailMsg);

    } catch (\Throwable $e) {
        error_log('[plugin_solicitud] Error notificando solicitante: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════
// Helper: mostrar error fatal y terminar
// ═══════════════════════════════════════════════════════════
function _form_page_error(string $msg): never
{
    header('Content-Type: text/html; charset=UTF-8');
    echo <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;
     min-height:100vh;background:#f0f2f5;margin:0}
.card{background:#fff;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.12);
      max-width:480px;width:100%;overflow:hidden}
.hdr{background:#dc3545;color:#fff;padding:24px 32px;text-align:center;font-size:18px;font-weight:700}
.bdy{padding:28px 32px;color:#444;line-height:1.6}
</style></head><body>
<div class="card">
  <div class="hdr">&#9888; Error</div>
  <div class="bdy"><p>{$msg}</p></div>
</div></body></html>
HTML;
    exit;
}
