<?php
/**
 * front/approval.php
 * Endpoint público: procesa la aprobación o rechazo del directivo vía link de email.
 *
 * GET /plugins/solicitud/front/approval.php?token=XXXX&action=approve|reject
 *
 * GLPI 11.x: el entorno Symfony ya está inicializado cuando este archivo se carga.
 * NO se debe hacer bootstrap manual (inc/includes.php no existe en GLPI 11).
 */

// ── 1. GLPI_ROOT: 3 niveles arriba desde front/ ───────────────────────────────
// front/ → solicitud/ → plugins/ → glpi/
if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(dirname(dirname(__DIR__))));
}

// ── 2. Cargar clases del plugin directamente ──────────────────────────────────
$pluginDir = dirname(__DIR__);
require_once $pluginDir . '/inc/approvaltoken.class.php';
require_once $pluginDir . '/inc/config.class.php';
require_once $pluginDir . '/inc/mail.php';
require_once $pluginDir . '/inc/solicitud.class.php';

// ── 3. Leer y sanear parámetros GET ──────────────────────────────────────────
$token  = isset($_GET['token'])  ? trim($_GET['token'])  : '';
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// Sanear token: solo caracteres hexadecimales
$token  = preg_replace('/[^a-f0-9]/i', '', $token);
$action = in_array($action, ['approve', 'reject'], true) ? $action : '';

// ── 4. Procesar la acción ────────────────────────────────────────────────────
$result = PluginSolicitud::processApproval($token, $action);

// ── 5. Renderizar página de confirmación ─────────────────────────────────────
$isSuccess = $result['ok'];
$message   = htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8');
$ticketId  = (int) $result['ticketId'];
$decision  = $result['decision'];

// Colores y etiquetas según decisión
if ($decision === 'approved') {
    $badgeClass = 'badge-ok';
    $icon       = '✔';
    $label      = 'Solicitud Aprobada';
    $accent     = '#28a745';
} elseif ($decision === 'rejected') {
    $badgeClass = 'badge-ko';
    $icon       = '✘';
    $label      = 'Solicitud Rechazada';
    $accent     = '#dc3545';
} else {
    $badgeClass = 'badge-warn';
    $icon       = '⚠';
    $label      = 'Error en la solicitud';
    $accent     = '#ffc107';
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $label ?> — GLPI</title>
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
    background: <?= $accent ?>;
    color: #fff;
    padding: 30px 32px 24px;
    text-align: center;
  }

  .card-header .icon {
    font-size: 52px;
    line-height: 1;
    margin-bottom: 10px;
  }

  .card-header h1 {
    font-size: 22px;
    font-weight: 700;
  }

  .card-body {
    padding: 28px 32px;
    color: #444;
    line-height: 1.6;
  }

  .card-body p {
    margin-bottom: 12px;
  }

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

  .<?= $badgeClass ?> { /* ya incluido en el header coloreado */ }
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <div class="icon"><?= $icon ?></div>
    <h1><?= $label ?></h1>
  </div>
  <div class="card-body">
    <p><?= $message ?></p>

    <?php if ($ticketId > 0): ?>
      <div>Ticket de referencia:</div>
      <span class="ticket-ref">#<?= $ticketId ?></span>
    <?php endif; ?>

    <?php if ($isSuccess): ?>
      <p>El área de IT ha sido notificada automáticamente.
         No es necesario realizar ninguna acción adicional por su parte.</p>
    <?php endif; ?>
  </div>
  <div class="card-footer">
    Este portal es gestionado por el sistema GLPI de su organización.<br>
    No responda a este mensaje.
  </div>
</div>
</body>
</html>
