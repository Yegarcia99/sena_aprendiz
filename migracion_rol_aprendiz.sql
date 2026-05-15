-- ============================================================
-- MIGRACIÓN: Agregar rol Aprendiz al sistema
-- Ejecutar en la base de datos de Railway ANTES del deploy
-- ============================================================

-- 1. Agregar 'Aprendiz' al enum de roles en usuarios
ALTER TABLE usuarios 
  MODIFY COLUMN rol ENUM('Administrador','Coordinador','Gestor','Instructor','Aprendiz') 
  DEFAULT 'Gestor';

-- 2. Agregar columna usuario_id en aprendices (para vincular aprendiz → usuario)
ALTER TABLE aprendices 
  ADD COLUMN IF NOT EXISTS usuario_id INT UNSIGNED NULL DEFAULT NULL AFTER id;

-- Agregar índice si no existe
ALTER TABLE aprendices 
  ADD INDEX IF NOT EXISTS idx_aprendiz_usuario_id (usuario_id);

-- 3. (Opcional) Agregar foreign key suave — solo si quieres integridad referencial
-- ALTER TABLE aprendices
--   ADD CONSTRAINT fk_aprendiz_usuario 
--   FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- ============================================================
-- CÓMO CREAR UN USUARIO APRENDIZ (ejemplo):
-- ============================================================
-- 1. En Gestión de Usuarios, crea el usuario con rol = 'Aprendiz'
-- 2. Luego vincula el usuario al aprendiz:
--    UPDATE aprendices SET usuario_id = <id_usuario> WHERE documento = '<documento_aprendiz>';
-- ============================================================
