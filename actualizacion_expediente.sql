-- Actualizacion: expediente integral del aprendiz
-- Ejecutar sobre la base de datos sena_aprendices si se prefiere migrar manualmente.

ALTER TABLE pendientes_aprendices
  ADD COLUMN tipo_caso VARCHAR(40) NOT NULL DEFAULT 'Academico' AFTER fecha_registro;

ALTER TABLE comite_aprendices
  ADD COLUMN caso_excepcional TINYINT(1) NOT NULL DEFAULT 0 AFTER decision,
  ADD COLUMN validacion_expediente TEXT NULL AFTER caso_excepcional;

ALTER TABLE acciones_remediales
  ADD COLUMN firma_instructor MEDIUMTEXT NULL,
  ADD COLUMN firma_aprendiz MEDIUMTEXT NULL;

CREATE TABLE IF NOT EXISTS planes_mejoramiento (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pendiente_id INT NOT NULL,
  aprendiz_id INT NOT NULL,
  instancia ENUM('Primera instancia','Segunda instancia') NOT NULL,
  fecha_concertacion DATE NOT NULL,
  evidencia_conocimiento TINYINT(1) NOT NULL DEFAULT 0,
  evidencia_producto TINYINT(1) NOT NULL DEFAULT 0,
  evidencia_desempeno TINYINT(1) NOT NULL DEFAULT 0,
  descripcion_plan TEXT NOT NULL,
  compromisos TEXT NULL,
  estado ENUM('Abierto','Cumplido','No cumplido','Cerrado') NOT NULL DEFAULT 'Abierto',
  instructor_id INT NULL,
  coordinador_id INT NULL,
  firma_instructor MEDIUMTEXT NULL,
  firma_coordinador MEDIUMTEXT NULL,
  firma_aprendiz MEDIUMTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pm_pendiente (pendiente_id),
  INDEX idx_pm_aprendiz (aprendiz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS soportes_expediente (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aprendiz_id INT NOT NULL,
  pendiente_id INT NULL,
  accion_id INT NULL,
  plan_id INT NULL,
  tipo_soporte VARCHAR(80) NOT NULL,
  descripcion TEXT NULL,
  archivo_nombre VARCHAR(255) NOT NULL,
  archivo_ruta VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  tamano INT NULL,
  subido_por INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_soporte_aprendiz (aprendiz_id),
  INDEX idx_soporte_pendiente (pendiente_id),
  INDEX idx_soporte_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notificaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aprendiz_id INT NOT NULL,
  pendiente_id INT NULL,
  referencia_tipo VARCHAR(40) NULL,
  referencia_id INT NULL,
  correo_destino VARCHAR(160) NOT NULL,
  asunto VARCHAR(180) NOT NULL,
  mensaje TEXT NOT NULL,
  estado_envio ENUM('Registrada','Enviada','Fallida') NOT NULL DEFAULT 'Registrada',
  enviado_por INT NULL,
  fecha_envio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_not_aprendiz (aprendiz_id),
  INDEX idx_not_pendiente (pendiente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
