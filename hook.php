<?php
/**
 * hook.php — Funciones de instalación, desinstalación y hooks de evento.
 */

defined('GLPI_ROOT') || die('Security breach!');

// ─── Instalación ──────────────────────────────────────────────────────────────

function plugin_solicitud_install(): bool
{
    /** @var \DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_SOLICITUD_VERSION);

    // Tabla de tokens de aprobación
    if (!$DB->tableExists('glpi_plugin_solicitud_tokens')) {
        $query = "CREATE TABLE `glpi_plugin_solicitud_tokens` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `token`         VARCHAR(128) NOT NULL DEFAULT '',
            `status`        VARCHAR(32)  NOT NULL DEFAULT 'pending',
            `approver_email`VARCHAR(255) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_action`   TIMESTAMP    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token` (`token`),
            KEY `tickets_id`  (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $DB->query($query) || die($DB->error());
    }

    // Tabla de configuración del plugin
    if (!$DB->tableExists('glpi_plugin_solicitud_configs')) {
        $query = "CREATE TABLE `glpi_plugin_solicitud_configs` (
            `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_name`       VARCHAR(255) NOT NULL DEFAULT 'Solicitud de Alta de Mail',
            `approver_email`      VARCHAR(255) NOT NULL DEFAULT '',
            `it_email`            VARCHAR(255) NOT NULL DEFAULT '',
            `glpi_base_url`       VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $DB->query($query) || die($DB->error());

        // Insertar fila de configuración por defecto
        $DB->insert('glpi_plugin_solicitud_configs', [
            'category_name'  => 'Solicitud de Alta de Mail',
            'approver_email' => 'directivo@empresa.com',
            'it_email'       => 'it@empresa.com',
            'glpi_base_url'  => 'https://glpi.local',
        ]);
    }

    $migration->executeMigration();
    return true;
}

// ─── Desinstalación ───────────────────────────────────────────────────────────

function plugin_solicitud_uninstall(): bool
{
    /** @var \DBmysql $DB */
    global $DB;

    $tables = [
        'glpi_plugin_solicitud_tokens',
        'glpi_plugin_solicitud_configs',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->query("DROP TABLE `$table`");
        }
    }

    return true;
}

// ─── Hook: ticket creado ──────────────────────────────────────────────────────

/**
 * Se ejecuta automáticamente cuando GLPI crea un Ticket nuevo.
 * Detecta si el ticket pertenece a la categoría configurada y dispara el flujo.
 *
 * @param Ticket $ticket  Objeto Ticket recién creado.
 */
function plugin_solicitud_ticket_created(Ticket $ticket): void
{
    /** @var \DBmysql $DB */
    global $DB;

    // ----- 1. Obtener configuración del plugin --------------------------------
    $configRow = $DB->request([
        'FROM'  => 'glpi_plugin_solicitud_configs',
        'LIMIT' => 1,
    ])->current();

    if (!$configRow) {
        return; // Plugin sin configurar
    }

    $categoryName  = $configRow['category_name'];
    $approverEmail = $configRow['approver_email'];
    $itEmail       = $configRow['it_email'];
    $baseUrl       = rtrim($configRow['glpi_base_url'], '/');

    // ----- 2. Obtener la categoría del ticket --------------------------------
    $ticketCategory = '';
    if (!empty($ticket->fields['itilcategories_id'])) {
        $catRow = $DB->request([
            'FROM'  => 'glpi_itilcategories',
            'WHERE' => ['id' => $ticket->fields['itilcategories_id']],
        ])->current();

        if ($catRow) {
            $ticketCategory = $catRow['name'] ?? '';
        }
    }

    // ----- 3. Verificar si la categoría coincide ----------------------------
    if (stripos($ticketCategory, $categoryName) === false) {
        // No es la categoría objetivo; ignorar este ticket
        return;
    }

    $ticketId    = (int) $ticket->fields['id'];
    $ticketTitle = $ticket->fields['name'] ?? "Ticket #$ticketId";

    // ----- 4. Generar token único -------------------------------------------
    $token = bin2hex(random_bytes(32)); // 64 chars hexadecimales

    $DB->insert('glpi_plugin_solicitud_tokens', [
        'tickets_id'     => $ticketId,
        'token'          => $token,
        'status'         => 'pending',
        'approver_email' => $approverEmail,
        'date_creation'  => date('Y-m-d H:i:s'),
    ]);

    // ----- 5. Construir URLs de acción --------------------------------------
    $approveUrl = "$baseUrl/plugins/solicitud/front/approval.php"
                . "?token=$token&action=approve";
    $rejectUrl  = "$baseUrl/plugins/solicitud/front/approval.php"
                . "?token=$token&action=reject";

    // ----- 6. Enviar email al directivo ------------------------------------
    plugin_solicitud_send_approval_email(
        $approverEmail,
        $ticketId,
        $ticketTitle,
        $approveUrl,
        $rejectUrl
    );

    // ----- 7. Agregar seguimiento al ticket indicando que se envió el mail --
    plugin_solicitud_add_followup(
        $ticketId,
        'Se ha enviado solicitud de aprobación al directivo vía email. '
        . 'Esperando respuesta.'
    );
}
