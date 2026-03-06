<?php
/**
 * PluginSolicitud — Clase principal del plugin.
 * Punto de entrada para la lógica de aprobación de solicitudes.
 */

defined('GLPI_ROOT') || die('Security breach!');

// Cargar helpers de mail
include_once PLUGIN_SOLICITUD_DIR . '/inc/mail.php';

class PluginSolicitud extends CommonGLPI
{
    public static $rightname = 'plugin_solicitud';

    // ─── Interfaz GLPI ───────────────────────────────────────────────────────

    public static function getTypeName($nb = 0): string
    {
        return 'Solicitud de Aprobación';
    }

    // ─── Procesamiento de aprobación / rechazo ────────────────────────────────

    /**
     * Procesa la acción enviada desde el email (approve o reject).
     * Retorna un array con el resultado de la operación.
     *
     * @param string $token   Token único del email.
     * @param string $action  'approve' | 'reject'
     * @return array{
     *   ok:        bool,
     *   message:   string,
     *   ticketId:  int,
     *   decision:  string
     * }
     */
    public static function processApproval(string $token, string $action): array
    {
        // 1. Validar parámetros básicos
        $action = in_array($action, ['approve', 'reject'], true) ? $action : '';
        if ($token === '' || $action === '') {
            return [
                'ok'       => false,
                'message'  => 'Petición inválida.',
                'ticketId' => 0,
                'decision' => '',
            ];
        }

        // 2. Buscar token en BD
        $row = PluginSolicitudApprovalToken::getByToken($token);
        if ($row === null) {
            return [
                'ok'       => false,
                'message'  => 'Token no encontrado.',
                'ticketId' => 0,
                'decision' => '',
            ];
        }

        // 3. Verificar expiración del token
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return [
                'ok'       => false,
                'message'  => 'Este enlace ha expirado. Por favor, contacte al área IT para generar uno nuevo.',
                'ticketId' => (int) $row['tickets_id'],
                'decision' => 'expired',
            ];
        }

        // 4. Verificar que no haya sido procesado ya
        if ($row['status'] !== 'pending') {
            $ya = ($row['status'] === 'approved') ? 'aprobada' : 'rechazada';
            return [
                'ok'       => false,
                'message'  => "Esta solicitud ya fue $ya con anterioridad.",
                'ticketId' => (int) $row['tickets_id'],
                'decision' => $row['status'],
            ];
        }

        $ticketId  = (int) $row['tickets_id'];
        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';

        // 4. Actualizar estado del token
        PluginSolicitudApprovalToken::updateStatus($token, $newStatus);

        // 5. Modificar el ticket en GLPI
        self::updateTicketStatus($ticketId, $action);

        // 6. Agregar seguimiento al ticket
        $followupContent = ($action === 'approve')
            ? 'Solicitud aprobada por el director vía email (acción automatizada por plugin Solicitud).'
            : 'Solicitud RECHAZADA por el director vía email (acción automatizada por plugin Solicitud).';
        plugin_solicitud_add_followup($ticketId, $followupContent);

        // 7. Notificar al área IT
        $config = PluginSolicitudConfig::getConfig();
        if (!empty($config['it_email'])) {
            plugin_solicitud_notify_it($config['it_email'], $ticketId, $action);
        }

        $humanAction = ($action === 'approve') ? 'aprobada' : 'rechazada';
        return [
            'ok'       => true,
            'message'  => "La solicitud ha sido $humanAction correctamente.",
            'ticketId' => $ticketId,
            'decision' => $newStatus,
        ];
    }

    // ─── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Actualiza el estado nativo del Ticket en GLPI.
     *
     * - approve → PLANNED (estado 3) + prefija el título con "[Aprobada por Director]"
     * - reject  → CLOSED  ("Cerrado", estado 6)
     *
     * @param int    $ticketId
     * @param string $action  'approve' | 'reject'
     */
    private static function updateTicketStatus(int $ticketId, string $action): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Mapeo de acción → valor numérico de estado de GLPI 11
        // Se usa $DB->update() directamente para evitar el chequeo de permisos
        // de Ticket::update(), que requiere un usuario autenticado en sesión.
        $statusMap = [
            'approve' => 3, // Ticket::PLANNED — base para "Solicitud aprobada por el director"
            'reject'  => 6, // Ticket::CLOSED  — "Cerrado"
        ];

        $newState = $statusMap[$action] ?? 6;

        $DB->update(
            'glpi_tickets',
            [
                'status'   => $newState,
                'date_mod' => date('Y-m-d H:i:s'),
            ],
            ['id' => $ticketId]
        );

        // Al aprobar: prefijar el título con "[Aprobada por Director]"
        // para que sea visible en la lista de tickets de GLPI.
        if ($action === 'approve') {
            $rows = $DB->request([
                'SELECT' => ['name'],
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['id' => $ticketId],
                'LIMIT'  => 1,
            ]);
            foreach ($rows as $row) {
                if (strpos($row['name'], '[Aprobada por Director]') === false) {
                    $DB->update(
                        'glpi_tickets',
                        ['name' => '[Aprobada por Director] ' . $row['name']],
                        ['id'   => $ticketId]
                    );
                }
            }
        }
    }
}
