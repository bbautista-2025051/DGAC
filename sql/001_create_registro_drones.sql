-- =============================================================================
-- DGAC · Registro de Drones
-- Migración 001: tabla de registros
--
-- IMPORTANTE
--   * En la aplicación existente ejecute ÚNICAMENTE la sección "TABLA" sobre
--     la base de datos que ya utiliza la aplicación. No cree una base nueva.
--   * La sección "BASE DE DATOS LOCAL" existe solo para levantar el entorno de
--     demostración/pruebas de este módulo cuando no hay una base configurada.
--   * Los campos solicitados se conservan con sus nombres exactos. Los campos
--     operativos adicionales (pdf_token, intentos_envio, etc.) son necesarios
--     para descargar el comprobante y reintentar el envío de correo.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- BASE DE DATOS LOCAL (solo entorno de pruebas; omitir en la app existente)
-- -----------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `dgac`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `dgac`;

-- -----------------------------------------------------------------------------
-- TABLA
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `registro_drones` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `marca_dron`         VARCHAR(80)  NOT NULL,
  `modelo`             VARCHAR(80)  NOT NULL,
  `numero_serie`       VARCHAR(40)  NOT NULL,
  `nombre_propietario` VARCHAR(150) NOT NULL,
  `correo_contacto`    VARCHAR(190) NOT NULL,
  `fecha_registro`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `estado_registro`    ENUM('REGISTRADO','CORREO_ENVIADO','CORREO_FALLIDO')
                       NOT NULL DEFAULT 'REGISTRADO',
  -- Campos operativos para el comprobante y el reintento de correo
  `pdf_token`          CHAR(64)     NOT NULL,
  `correo_enviado_en`  DATETIME     NULL DEFAULT NULL,
  `intentos_envio`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ultimo_error`       VARCHAR(500) NULL DEFAULT NULL,
  `actualizado_en`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_registro_drones_numero_serie` (`numero_serie`),
  UNIQUE KEY `uq_registro_drones_pdf_token` (`pdf_token`),
  KEY `idx_registro_drones_correo` (`correo_contacto`),
  KEY `idx_registro_drones_fecha` (`fecha_registro`),
  KEY `idx_registro_drones_estado` (`estado_registro`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- USUARIO DE BASE DE DATOS (ejemplo; ajústelo a la política de la DGAC)
-- No ejecute este bloque si la aplicación existente ya tiene un usuario.
-- -----------------------------------------------------------------------------
-- CREATE USER 'dgac_drones'@'localhost' IDENTIFIED BY 'CAMBIE_ESTA_CLAVE';
-- GRANT SELECT, INSERT, UPDATE ON `dgac`.`registro_drones` TO 'dgac_drones'@'localhost';
-- FLUSH PRIVILEGES;
