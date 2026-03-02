<?php
/**
 * PluginSolicitudConfig
 * Acceso a la configuración almacenada en glpi_plugin_solicitud_configs.
 */

defined('GLPI_ROOT') || die('Security breach!');

class PluginSolicitudConfig extends CommonDBTM
{
    public static $rightname = 'config';

    /**
     * Retorna la fila de configuración actual o valores por defecto.
     *
     * @return array{
     *   category_name: string,
     *   approver_email: string,
     *   it_email: string,
     *   glpi_base_url: string
     * }
     */
    public static function getConfig(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $defaults = [
            'category_name'  => 'Solicitud de Alta de Mail',
            'approver_email' => '',
            'it_email'       => '',
            'glpi_base_url'  => 'https://glpi.local',
        ];

        try {
            $row = $DB->request([
                'FROM'  => 'glpi_plugin_solicitud_configs',
                'LIMIT' => 1,
            ])->current();

            return $row ? array_merge($defaults, (array) $row) : $defaults;
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Guarda la configuración (actualiza la única fila o la inserta).
     *
     * @param array $data  Claves: category_name, approver_email, it_email, glpi_base_url
     */
    public static function saveConfig(array $data): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $allowed = ['category_name', 'approver_email', 'it_email', 'glpi_base_url'];
        $clean   = array_intersect_key($data, array_flip($allowed));

        $existing = $DB->request([
            'FROM'   => 'glpi_plugin_solicitud_configs',
            'SELECT' => ['id'],
            'LIMIT'  => 1,
        ])->current();

        if ($existing) {
            $DB->update('glpi_plugin_solicitud_configs', $clean, ['id' => $existing['id']]);
        } else {
            $DB->insert('glpi_plugin_solicitud_configs', $clean);
        }
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_solicitud_configs';
    }
}
