-- ============================================================
--  install.sql — Plugin Solicitud para GLPI 11.x
--  Ejecutar al instalar el plugin.
-- ============================================================

-- ── Tabla de tokens de aprobación ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `glpi_plugin_solicitud_tokens` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `tickets_id`      INT UNSIGNED    NOT NULL DEFAULT 0
                          COMMENT 'FK → glpi_tickets.id',
    `token`           VARCHAR(128)    NOT NULL DEFAULT ''
                          COMMENT 'Token hexadecimal único enviado por email',
    `status`          ENUM('pending','approved','rejected')
                          NOT NULL DEFAULT 'pending'
                          COMMENT 'Estado actual del token',
    `approver_email`  VARCHAR(255)    NOT NULL DEFAULT ''
                          COMMENT 'Email del director al que se envió el mail',
    `date_creation`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_action`     TIMESTAMP       NULL     DEFAULT NULL
                          COMMENT 'Timestamp en que el director tomó la decisión',
    `expires_at`      DATETIME        NULL     DEFAULT NULL
                          COMMENT 'Fecha/hora límite para usar el token (NULL = sin expiración)',
    `form_token`      VARCHAR(128)    NULL     DEFAULT NULL
                          COMMENT 'Token para el formulario de Cómputos (generado al aprobar)',
    PRIMARY KEY  (`id`),
    UNIQUE  KEY  `token`      (`token`),
    KEY          `tickets_id` (`tickets_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Plugin Solicitud — tokens de aprobación de tickets';

-- ── Tabla de configuración ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `glpi_plugin_solicitud_configs` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `category_name`   VARCHAR(255)    NOT NULL DEFAULT 'Solicitud de Alta de Mail'
                          COMMENT 'Nombre de la categoría GLPI que activa el flujo',
    `approver_email`  VARCHAR(255)    NOT NULL DEFAULT ''
                          COMMENT 'Email por defecto del director aprobador',
    `it_email`        VARCHAR(255)    NOT NULL DEFAULT ''
                          COMMENT 'Email del área IT para recibir notificaciones',
    `computos_email`  VARCHAR(255)    NOT NULL DEFAULT ''
                          COMMENT 'Email del área de Cómputos para recibir el formulario de alta',
    `glpi_base_url`   VARCHAR(255)    NOT NULL DEFAULT 'https://glpi.local'
                          COMMENT 'URL base de GLPI (sin barra final)',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Plugin Solicitud — configuración general';

-- ── Fila de configuración por defecto ─────────────────────────────────────────
INSERT IGNORE INTO `glpi_plugin_solicitud_configs`
    (`category_name`, `approver_email`, `it_email`, `computos_email`, `glpi_base_url`)
VALUES
    ('Solicitud de creacion de mail', 'director@empresa.com', 'it@empresa.com', 'computos@empresa.com', 'https://glpi.local');
