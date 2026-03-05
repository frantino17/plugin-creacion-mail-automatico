<?php
/**
 * PluginSolicitudApprovalToken
 * Gestiona los tokens de aprobación almacenados en base de datos.
 */

defined('GLPI_ROOT') || die('Security breach!');

class PluginSolicitudApprovalToken extends CommonDBTM
{
    // Nombre de la tabla en BD (GLPI lo infiere automáticamente del nombre de clase,
    // pero lo declaramos explícitamente para mayor claridad)
    public static $rightname = 'plugin_solicitud_token';

    // ── CRUD helpers ──────────────────────────────────────────────────────────

    /**
     * Crea un token nuevo para el ticket indicado.
     *
     * @param int    $ticketId       ID del ticket en GLPI.
     * @param string $approverEmail  Email del directivo aprobador.
     * @return string  El token generado.
     */
    /**
     * Horas de validez de cada token antes de expirar.
     * Cambiar a 0 para deshabilitar la expiración.
     */
    public const TOKEN_TTL_HOURS = 48;

    public static function createForTicket(int $ticketId, string $approverEmail): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $token     = bin2hex(random_bytes(32)); // 64 chars hex
        $expiresAt = (self::TOKEN_TTL_HOURS > 0)
            ? date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_TTL_HOURS . ' hours'))
            : null;

        $DB->insert('glpi_plugin_solicitud_tokens', [
            'tickets_id'     => $ticketId,
            'token'          => $token,
            'status'         => 'pending',
            'approver_email' => $approverEmail,
            'date_creation'  => date('Y-m-d H:i:s'),
            'expires_at'     => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Busca un registro por token.
     *
     * @param string $token
     * @return array|null  Fila de la BD o null si no existe.
     */
    public static function getByToken(string $token): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_solicitud_tokens',
            'WHERE' => ['token' => $token],
            'LIMIT' => 1,
        ])->current();

        return $row ?: null;
    }

    /**
     * Actualiza el estado de un token (approved / rejected).
     *
     * @param string $token
     * @param string $status  'approved' o 'rejected'
     */
    public static function updateStatus(string $token, string $status): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(
            'glpi_plugin_solicitud_tokens',
            [
                'status'      => $status,
                'date_action' => date('Y-m-d H:i:s'),
            ],
            ['token' => $token]
        );
    }

    /**
     * Retorna la tabla asociada (necesario para CommonDBTM).
     */
    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_solicitud_tokens';
    }
}
