-- =============================================================================
-- DGAC · Registro de Drones
-- Migración 003: tabla de drones (del archivo DB_CDAG.sql)
--=============================================================================

CREATE TABLE IF NOT EXISTS `drones` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `modelo`           VARCHAR(67)  NOT NULL,
  `marca_dron`       VARCHAR(67)  NOT NULL,
  `numero_serie`     INT UNSIGNED NOT NULL,
  `nombre_propietario` VARCHAR(67) NOT NULL,
  `correo_contacto`  VARCHAR(67)  NOT NULL UNIQUE,
  `fecha_registro`   DATE         NOT NULL,
  `estado_registro`  ENUM('activo','inactivo') NOT NULL DEFAULT 'activo'
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;