<?php
// Soporte de expediente academico: tablas nuevas y utilidades de archivos.

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function ensureExpedienteSchema(PDO $db): void {
    $db->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!columnExists($db, 'comite_aprendices', 'caso_excepcional')) {
        $db->exec("ALTER TABLE comite_aprendices ADD COLUMN caso_excepcional TINYINT(1) NOT NULL DEFAULT 0 AFTER decision");
    }
    if (!columnExists($db, 'comite_aprendices', 'validacion_expediente')) {
        $db->exec("ALTER TABLE comite_aprendices ADD COLUMN validacion_expediente TEXT NULL AFTER caso_excepcional");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'tipo_caso')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN tipo_caso VARCHAR(40) NOT NULL DEFAULT 'Academico' AFTER fecha_registro");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'estado_flujo')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN estado_flujo VARCHAR(40) NOT NULL DEFAULT 'Reportado' AFTER estado");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'gestor_id')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN gestor_id INT NULL AFTER instructor_id");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'coordinador_id')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN coordinador_id INT NULL AFTER gestor_id");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'fecha_limite_actual')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN fecha_limite_actual DATE NULL AFTER fecha_registro");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'instancia_actual')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN instancia_actual TINYINT(1) NOT NULL DEFAULT 0 AFTER estado_flujo");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'fecha_primera_instancia')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN fecha_primera_instancia DATE NULL AFTER instancia_actual");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'fecha_segunda_instancia')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN fecha_segunda_instancia DATE NULL AFTER fecha_primera_instancia");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'habilitado_comite')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN habilitado_comite TINYINT(1) NOT NULL DEFAULT 0 AFTER fecha_segunda_instancia");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'fecha_habilitado_comite')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN fecha_habilitado_comite DATETIME NULL AFTER habilitado_comite");
    }
    if (!columnExists($db, 'pendientes_aprendices', 'motivo_habilitado_comite')) {
        $db->exec("ALTER TABLE pendientes_aprendices ADD COLUMN motivo_habilitado_comite TEXT NULL AFTER fecha_habilitado_comite");
    }
    if (!columnExists($db, 'acciones_remediales', 'fecha_limite')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN fecha_limite DATE NULL AFTER fecha_accion");
    }
    if (!columnExists($db, 'acciones_remediales', 'requiere_trabajo')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN requiere_trabajo TINYINT(1) NOT NULL DEFAULT 0 AFTER tipo_accion");
    }
    if (!columnExists($db, 'acciones_remediales', 'requiere_evidencia')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN requiere_evidencia TINYINT(1) NOT NULL DEFAULT 0 AFTER requiere_trabajo");
    }
    if (!columnExists($db, 'acciones_remediales', 'requiere_sustentacion')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN requiere_sustentacion TINYINT(1) NOT NULL DEFAULT 0 AFTER requiere_evidencia");
    }
    if (!columnExists($db, 'acciones_remediales', 'requiere_evaluacion')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN requiere_evaluacion TINYINT(1) NOT NULL DEFAULT 0 AFTER requiere_sustentacion");
    }
    if (!columnExists($db, 'acciones_remediales', 'requiere_tutoria')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN requiere_tutoria TINYINT(1) NOT NULL DEFAULT 0 AFTER requiere_evaluacion");
    }
    if (!columnExists($db, 'acciones_remediales', 'otra_actividad')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN otra_actividad VARCHAR(180) NULL AFTER requiere_tutoria");
    }
    if (!columnExists($db, 'acciones_remediales', 'indicaciones_aprendiz')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN indicaciones_aprendiz TEXT NULL AFTER otra_actividad");
    }
    if (!columnExists($db, 'acciones_remediales', 'estado_entrega')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN estado_entrega VARCHAR(30) NOT NULL DEFAULT 'Pendiente' AFTER resultado");
    }
    if (!columnExists($db, 'acciones_remediales', 'fecha_entrega')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN fecha_entrega DATETIME NULL AFTER estado_entrega");
    }
    if (!columnExists($db, 'acciones_remediales', 'observacion_entrega')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN observacion_entrega TEXT NULL AFTER fecha_entrega");
    }
    if (!columnExists($db, 'acciones_remediales', 'estado_revision')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN estado_revision VARCHAR(30) NOT NULL DEFAULT 'Pendiente' AFTER observacion_entrega");
    }
    if (!columnExists($db, 'acciones_remediales', 'fecha_revision')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN fecha_revision DATETIME NULL AFTER estado_revision");
    }
    if (!columnExists($db, 'acciones_remediales', 'observacion_revision')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN observacion_revision TEXT NULL AFTER fecha_revision");
    }
    if (!columnExists($db, 'acciones_remediales', 'revisado_por')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN revisado_por INT NULL AFTER observacion_revision");
    }
    if (!columnExists($db, 'acciones_remediales', 'firma_instructor')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN firma_instructor MEDIUMTEXT NULL");
    }
    if (!columnExists($db, 'acciones_remediales', 'firma_aprendiz')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN firma_aprendiz MEDIUMTEXT NULL");
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS academico_flujo_eventos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pendiente_id INT NOT NULL,
            aprendiz_id INT NOT NULL,
            accion_id INT NULL,
            plan_id INT NULL,
            tipo_evento VARCHAR(60) NOT NULL,
            estado_anterior VARCHAR(40) NULL,
            estado_nuevo VARCHAR(40) NOT NULL,
            instancia TINYINT(1) NOT NULL DEFAULT 0,
            descripcion TEXT NULL,
            fecha_limite DATE NULL,
            creado_por INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_afe_pendiente (pendiente_id),
            INDEX idx_afe_aprendiz (aprendiz_id),
            INDEX idx_afe_tipo (tipo_evento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS auditoria_cambios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NULL,
            usuario_nombre VARCHAR(180) NULL,
            usuario_rol VARCHAR(40) NULL,
            modulo VARCHAR(60) NOT NULL,
            accion VARCHAR(80) NOT NULL,
            entidad_tipo VARCHAR(60) NULL,
            entidad_id INT NULL,
            aprendiz_id INT NULL,
            pendiente_id INT NULL,
            descripcion TEXT NULL,
            valor_anterior TEXT NULL,
            valor_nuevo TEXT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_aud_usuario (usuario_id),
            INDEX idx_aud_aprendiz (aprendiz_id),
            INDEX idx_aud_pendiente (pendiente_id),
            INDEX idx_aud_modulo (modulo),
            INDEX idx_aud_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function registrarAuditoria(PDO $db, array $data): void {
    $user = function_exists('getCurrentUser') ? getCurrentUser() : [];
    $nombreUsuario = trim(($user['nombres'] ?? '') . ' ' . ($user['apellidos'] ?? ''));

    $stmt = $db->prepare("
        INSERT INTO auditoria_cambios
        (usuario_id, usuario_nombre, usuario_rol, modulo, accion, entidad_tipo, entidad_id, aprendiz_id, pendiente_id, descripcion, valor_anterior, valor_nuevo, ip, user_agent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        (int)($user['id'] ?? 0) ?: null,
        $nombreUsuario ?: null,
        $user['rol'] ?? null,
        $data['modulo'] ?? 'Sistema',
        $data['accion'] ?? 'Cambio',
        $data['entidad_tipo'] ?? null,
        !empty($data['entidad_id']) ? (int)$data['entidad_id'] : null,
        !empty($data['aprendiz_id']) ? (int)$data['aprendiz_id'] : null,
        !empty($data['pendiente_id']) ? (int)$data['pendiente_id'] : null,
        $data['descripcion'] ?? null,
        isset($data['valor_anterior']) ? (string)$data['valor_anterior'] : null,
        isset($data['valor_nuevo']) ? (string)$data['valor_nuevo'] : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}

function expedienteUploadDir(): string {
    $dir = __DIR__ . '/../uploads/expedientes';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function guardarSoporteExpediente(PDO $db, array $file, array $data): void {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo cargar el archivo de soporte.');
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('El soporte no puede superar 8 MB.');
    }

    $original = basename($file['name']);
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original);
    $stored = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
    $target = expedienteUploadDir() . DIRECTORY_SEPARATOR . $stored;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('No se pudo guardar el archivo de soporte.');
    }

    $relativePath = 'uploads/expedientes/' . $stored;
    $stmt = $db->prepare("
        INSERT INTO soportes_expediente
        (aprendiz_id, pendiente_id, accion_id, plan_id, tipo_soporte, descripcion, archivo_nombre, archivo_ruta, mime_type, tamano, subido_por)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $data['aprendiz_id'],
        $data['pendiente_id'] ?? null,
        $data['accion_id'] ?? null,
        $data['plan_id'] ?? null,
        $data['tipo_soporte'],
        $data['descripcion'] ?? null,
        $original,
        $relativePath,
        $file['type'] ?? null,
        $file['size'] ?? null,
        $data['subido_por'] ?? null,
    ]);
}

function diagnosticoExpediente(PDO $db, int $aprendizId): array {
    $pend = $db->prepare("SELECT COUNT(*) FROM pendientes_aprendices WHERE aprendiz_id=? AND estado NOT IN ('Superado','Cerrado')");
    $pend->execute([$aprendizId]);
    $pendientes = (int)$pend->fetchColumn();

    $planes = $db->prepare("SELECT COUNT(*) FROM planes_mejoramiento WHERE aprendiz_id=?");
    $planes->execute([$aprendizId]);
    $totalPlanes = (int)$planes->fetchColumn();

    $soportes = $db->prepare("SELECT COUNT(*) FROM soportes_expediente WHERE aprendiz_id=?");
    $soportes->execute([$aprendizId]);
    $totalSoportes = (int)$soportes->fetchColumn();

    $acciones = $db->prepare("
        SELECT COUNT(*)
        FROM acciones_remediales ar
        JOIN pendientes_aprendices pa ON pa.id=ar.pendiente_id
        WHERE pa.aprendiz_id=?
    ");
    $acciones->execute([$aprendizId]);
    $totalAcciones = (int)$acciones->fetchColumn();

    $faltantes = [];
    if ($pendientes === 0) $faltantes[] = 'No tiene pendientes activos.';
    if ($totalAcciones === 0) $faltantes[] = 'No tiene acciones remediales ni justificacion registrada.';
    if ($totalPlanes === 0) $faltantes[] = 'No tiene plan de mejoramiento de primera instancia.';
    if ($totalSoportes === 0) $faltantes[] = 'No tiene soportes adjuntos.';

    return [
        'pendientes' => $pendientes,
        'acciones' => $totalAcciones,
        'planes' => $totalPlanes,
        'soportes' => $totalSoportes,
        'faltantes' => $faltantes,
        'completo' => empty($faltantes),
    ];
}
