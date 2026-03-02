-- ============================================================
--  uninstall.sql — Plugin Solicitud para GLPI 11.x
--  Se ejecuta al desinstalar el plugin desde el panel de GLPI.
-- ============================================================

DROP TABLE IF EXISTS `glpi_plugin_solicitud_tokens`;
DROP TABLE IF EXISTS `glpi_plugin_solicitud_configs`;
