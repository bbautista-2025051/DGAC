-- =============================================================================
-- DGAC · Registro de Drones
-- Migración 002: usuarios y roles para consulta interna
--
-- IMPORTANTE
--   * Esta migración NO crea usuarios: para evitar credenciales por defecto,
--     créelas después de aplicarla con `scripts/crear-usuario.php`.
--   * No contiene contraseñas ni datos de prueba. En producción se ejecuta la
--     sección TABLA sobre la misma base de datos que usa la aplicación.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- TABLA
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios_dgac` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`             VARCHAR(150) NOT NULL,
  `correo`             VARCHAR(190) NOT NULL,
  `password_hash`      VARCHAR(255) NOT NULL,
  `rol`                ENUM('admin', 'consulta') NOT NULL DEFAULT 'consulta',
  `activo`             TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `intentos_fallidos`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `bloqueado_hasta`    DATETIME NULL DEFAULT NULL,
  `ultimo_acceso`      DATETIME NULL DEFAULT NULL,
  `creado_en`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_dgac_correo` (`correo`),
  KEY `idx_usuarios_dgac_rol_activo` (`rol`, `activo`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ROLES
--   * `consulta`: personal interno con acceso de solo lectura a la búsqueda y al
--     listado. No puede descargar comprobantes.
--   * `admin`: personal interno autorizado; puede buscar, listar y descargar
--     comprobantes PDF.
--   * El público general no puede listar: solo puede buscar su registro con
--     número de serie + correo y no puede descargar el PDF.
--
-- Para crear el primer usuario use, desde la raíz del proyecto:
--   php scripts/crear-usuario.php --nombre="Administración DGAC" \
--     --correo="admin@dgac.gob.gt" --rol=admin
-- Si no se pasa --password, el script genera una contraseña temporal.
-- -----------------------------------------------------------------------------
