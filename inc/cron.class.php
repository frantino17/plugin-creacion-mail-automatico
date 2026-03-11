<?php
/**
 * PluginSolicitudCron
 * Tarea programada: verifica solicitudes de aprobación pendientes,
 * muestra el tiempo transcurrido en el ticket mediante seguimientos privados,
 * y reenvía el email al director cuando se cumplen las 48 horas sin respuesta.
 */

defined('GLPI_ROOT') || die('Security breach!');

require_once __DIR__ . '/approvaltoken.class.php';
require_once __DIR__ . '/mail.php';

class PluginSolicitudCron extends CommonGLPI
{
    // ── Descripción de las tareas disponibles (GLPI las lee para el panel) ────
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'CheckPendingApprovals' => [
                'description' => 'Verifica solicitudes pendientes de aprobación, '
                               . 'agrega recordatorios en el ticket y reenvía el '
                               . 'email al director al cumplirse las 48 horas.',
            ],
            default => [],
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tarea principal
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GLPI invoca este método según la frecuencia registrada (cada hora).
     *
     * Lógica por token pendiente:
     *  - Cada 12 horas transcurridas → followup privado "han pasado Xh, quedan Yh"
     *  - Al cumplir 48 h (expires_at) → reenvío del email + nuevo token + followup
     *
     * @param  CronTask $task  Objeto de tarea inyectado por GLPI.
     * @return int  1 = éxito/trabajo realizado, 0 = nada que hacer.
     */
    public static function cronCheckPendingApprovals(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now       = time();
        $nowStr    = date('Y-m-d H:i:s', $now);
        $processed = 0;

        // ── Leer configuración global del plugin ──────────────────────────────
        $configRow = $DB->request([
            'FROM'  => 'glpi_plugin_solicitud_configs',
            'LIMIT' => 1,
        ])->current();

        if (!$configRow) {
            return 0; // Plugin sin configurar
        }

        $baseUrl = rtrim($configRow['glpi_base_url'], '/');

        // ── Obtener todos los tokens pendientes ───────────────────────────────
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_solicitud_tokens',
            'WHERE' => ['status' => 'pending'],
        ]);

        foreach ($iterator as $row) {
            $ticketId    = (int) $row['tickets_id'];
            $tokenId     = (int) $row['id'];
            $createdAt   = strtotime($row['date_creation']);
            $expiresAt   = !empty($row['expires_at'])
                           ? strtotime($row['expires_at'])
                           : ($createdAt + 48 * 3600);
            $sendCount   = (int) ($row['send_count']   ?? 0);
            $lastRemTs   = !empty($row['last_reminder_sent'])
                           ? strtotime($row['last_reminder_sent'])
                           : 0;

            $elapsedSec        = $now - $createdAt;
            $elapsedHours      = $elapsedSec / 3600;
            $remainingSec      = $expiresAt - $now;
            $remainingHours    = $remainingSec / 3600;
            $sinceReminderHrs  = $lastRemTs ? ($now - $lastRemTs) / 3600 : PHP_INT_MAX;

            // ── CASO A: plazo de 48 h cumplido → reenviar email ───────────────
            if ($now >= $expiresAt) {
                $processed += self::_resendEmail(
                    $DB, $row, $ticketId, $tokenId,
                    $configRow, $baseUrl, $sendCount, $nowStr
                );
                $task->addVolume(1);
                continue;
            }

            // ── CASO B: recordatorio cada 12 h (solo si aún quedan > 1 h) ────
            if ($sinceReminderHrs >= 12 && $elapsedHours >= 12 && $remainingHours > 1) {
                $elapsed = (int) round($elapsedHours);
                $rem     = (int) round($remainingHours);

                $DB->insert('glpi_itilfollowups', [
                    'itemtype'        => 'Ticket',
                    'items_id'        => $ticketId,
                    'users_id'        => 0,
                    'content'         =>
                        "⏱ Recordatorio automático (solicitud pendiente de aprobación):\n\n"
                        . "  • Tiempo transcurrido  : {$elapsed} horas\n"
                        . "  • Tiempo restante      : {$rem} horas\n\n"
                        . "Si el director no responde antes del plazo, el sistema reenviará "
                        . "el correo automáticamente.",
                    'is_private'      => 1,
                    'requesttypes_id' => 0,
                    'date'            => $nowStr,
                    'date_creation'   => $nowStr,
                    'date_mod'        => $nowStr,
                ]);

                $DB->update(
                    'glpi_plugin_solicitud_tokens',
                    ['last_reminder_sent' => $nowStr],
                    ['id' => $tokenId]
                );

                $processed++;
                $task->addVolume(1);
            }
        }

        return $processed > 0 ? 1 : 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper privado: reenviar email y generar nuevo token
    // ─────────────────────────────────────────────────────────────────────────

    private static function _resendEmail(
        \DBmysql $DB,
        array    $row,
        int      $ticketId,
        int      $tokenId,
        array    $configRow,
        string   $baseUrl,
        int      $sendCount,
        string   $nowStr
    ): int {
        $approverEmail = ($row['approver_email'] !== '')
                         ? $row['approver_email']
                         : $configRow['approver_email'];

        // 1. Invalidar token actual
        $DB->update(
            'glpi_plugin_solicitud_tokens',
            ['status' => 'resent'],
            ['id'     => $tokenId]
        );

        // 2. Crear nuevo token (con nuevo plazo de 48 h)
        $newToken = PluginSolicitudApprovalToken::createForTicket($ticketId, $approverEmail);

        // 3. Asignar send_count al nuevo token
        $DB->update(
            'glpi_plugin_solicitud_tokens',
            ['send_count' => $sendCount + 1],
            ['tickets_id' => $ticketId, 'token' => $newToken]
        );

        // 4. Construir URLs con el nuevo token
        $approveUrl = "$baseUrl/plugins/solicitud/front/approval.php"
                    . "?token=$newToken&action=approve";
        $rejectUrl  = "$baseUrl/plugins/solicitud/front/approval.php"
                    . "?token=$newToken&action=reject";

        // 5. Obtener título del ticket
        $ticketRow   = $DB->request([
            'FROM'  => 'glpi_tickets',
            'WHERE' => ['id' => $ticketId],
            'LIMIT' => 1,
        ])->current();
        $ticketTitle = $ticketRow['name'] ?? "Pedido #$ticketId";

        // 6. Reenviar email al director
        plugin_solicitud_send_approval_email(
            $approverEmail,
            $ticketId,
            $ticketTitle,
            $approveUrl,
            $rejectUrl
        );

        // 7. Nuevo plazo calculado
        $newDeadline = date('d/m/Y \a\s H:i', strtotime('+' . PluginSolicitudApprovalToken::TOKEN_TTL_HOURS . ' hours'));
        $attempt     = $sendCount + 1;

        // 8. Agregar followup informativo al ticket
        $DB->insert('glpi_itilfollowups', [
            'itemtype'        => 'Ticket',
            'items_id'        => $ticketId,
            'users_id'        => 0,
            'content'         =>
                "🔄 Reenvío automático #$attempt — El director no respondió en "
                . PluginSolicitudApprovalToken::TOKEN_TTL_HOURS . " horas.\n\n"
                . "  • Email reenviado a : $approverEmail\n"
                . "  • Nuevo plazo límite : $newDeadline\n\n"
                . "El enlace anterior quedó desactivado. Se generó un nuevo enlace de aprobación.",
            'is_private'      => 1,
            'requesttypes_id' => 0,
            'date'            => $nowStr,
            'date_creation'   => $nowStr,
            'date_mod'        => $nowStr,
        ]);

        return 1;
    }
}
