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

        // 3. Verificar que no haya sido procesado ya
        if ($row['status'] !== 'pending') {
            $ya = ($row['status'] === 'approved') ? 'aprobada' : 'rechazada';
            return [
                'ok'       => false,
                'message'  => "Esta solicitud ya fue $ya con anterioridad.",
                'ticketId' => (int) $row['tickets_id'],
                'decision' => $row['status'],
            ];
        }

        $ticketId = (int) $row['tickets_id'];
        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';

        // 4. Actualizar estado del token
        PluginSolicitudApprovalToken::updateStatus($token, $newStatus);

        // 5. Modificar el ticket en GLPI
        self::updateTicketStatus($ticketId, $action);

        // 6. Agregar seguimiento al ticket
        $label = ($action === 'approve') ? 'APROBADA' : 'RECHAZADA';
        plugin_solicitud_add_followup(
            $ticketId,
            "Solicitud $label por el directivo vía email (acción automatizada por plugin Solicitud)."
        );

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
     * - approve → SOLVED  (estado 5)
     * - reject  → CLOSED  (estado 6)
     *
     * @param int    $ticketId
     * @param string $action
     */
    private static function updateTicketStatus(int $ticketId, string $action): void
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return;
        }

        // Mapeo de acción → estado nativo de GLPI
        $statusMap = [
            'approve' => Ticket::SOLVED,  // 5
            'reject'  => Ticket::CLOSED,  // 6
        ];

        $newState = $statusMap[$action] ?? Ticket::CLOSED;

        $ticket->update([
            'id'     => $ticketId,
            'status' => $newState,
        ]);
    }
}
