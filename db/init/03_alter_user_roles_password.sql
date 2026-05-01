-- =====================================================================
-- ATLAS — Migración: agregar columna password a user_roles
-- Idempotente: sólo agrega la columna si no existe.
-- =====================================================================

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'user_roles'
    AND COLUMN_NAME  = 'password'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE user_roles ADD COLUMN password VARCHAR(255) NULL AFTER email',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
