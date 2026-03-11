<?php
/**
 * Plugin Solicitud - GLPI 11.x
 * Gestión de solicitudes con aprobación/rechazo vía email sin login.
 */

defined('GLPI_ROOT') || die('Security breach!');

// ─── Constantes del plugin ────────────────────────────────────────────────────
define('PLUGIN_SOLICITUD_VERSION',  '1.0.0');
define('PLUGIN_SOLICITUD_MIN_GLPI', '11.0.0');
define('PLUGIN_SOLICITUD_MAX_GLPI', '11.99.99');
define('PLUGIN_SOLICITUD_DIR',      __DIR__);
define('PLUGIN_SOLICITUD_WEBDIR',   Plugin::getWebDir('solicitud', true));

/**
 * Nombre y metadatos del plugin (requerido por GLPI).
 */
function plugin_version_solicitud(): array
{
    return [
        'name'         => 'Solicitud de Aprobación',
        'version'      => PLUGIN_SOLICITUD_VERSION,
        'author'       => 'Equipo IT',
        'license'      => 'GPL v2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_SOLICITUD_MIN_GLPI,
                'max' => PLUGIN_SOLICITUD_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.0',
            ],
        ],
    ];
}

/**
 * Verificación de prerrequisitos antes de instalar.
 */
function plugin_solicitud_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_SOLICITUD_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_SOLICITUD_MAX_GLPI, 'gt')
    ) {
        echo 'Este plugin requiere GLPI entre ' . PLUGIN_SOLICITUD_MIN_GLPI
             . ' y ' . PLUGIN_SOLICITUD_MAX_GLPI;
        return false;
    }
    return true;
}

/**
 * Verificación de configuración antes de activar.
 */
function plugin_solicitud_check_config(): bool
{
    return true;
}

/**
 * Inicialización del plugin: registro de hooks y clases.
 */
function plugin_init_solicitud(): void
{
    global $PLUGIN_HOOKS;

    // Compatibilidad CSRF de GLPI
    $PLUGIN_HOOKS['csrf_compliant']['solicitud'] = true;

    // ── Funciones de instalación/desinstalación ───────────────────────────────
    $PLUGIN_HOOKS['install']['solicitud']   = 'plugin_solicitud_install';
    $PLUGIN_HOOKS['uninstall']['solicitud'] = 'plugin_solicitud_uninstall';

    if (Plugin::isPluginActive('solicitud')) {
        // ── Hook: se ejecuta cuando se CREA un ticket ─────────────────────────
        $PLUGIN_HOOKS['item_add']['solicitud'] = [
            'Ticket' => 'plugin_solicitud_ticket_created',
        ];
        // ── Hook: se ejecuta cuando se ACTUALIZA un ticket ────────────────
        // Detecta el cierre (status=6) por IT para enviar el correo institucional.
        $PLUGIN_HOOKS['item_update']['solicitud'] = [
            'Ticket' => 'plugin_solicitud_ticket_updated',
        ];
        // ── Registro de clases del plugin ─────────────────────────────────────
        Plugin::registerClass('PluginSolicitudApprovalToken');
        Plugin::registerClass('PluginSolicitudConfig');        Plugin::registerClass('PluginSolicitudCron');

        // ── Tarea programada: verificar solicitudes pendientes cada hora ──────
        if (class_exists('CronTask')) {
            CronTask::register(
                'PluginSolicitudCron',       // clase
                'CheckPendingApprovals',     // nombre (método: cronCheckPendingApprovals)
                3600,                        // frecuencia: 1 hora en segundos
                [
                    'comment' => 'Verifica aprobaciones pendientes, agrega recordatorios '
                               . 'y reenvía el email al director al cumplirse 48 h.',
                    'mode'    => CronTask::MODE_INTERNAL,
                    'state'   => CronTask::STATE_WAITING,
                ]
            );
        }    }
}
