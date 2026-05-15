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
    if (!columnExists($db, 'acciones_remediales', 'firma_instructor')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN firma_instructor MEDIUMTEXT NULL");
    }
    if (!columnExists($db, 'acciones_remediales', 'firma_aprendiz')) {
        $db->exec("ALTER TABLE acciones_remediales ADD COLUMN firma_aprendiz MEDIUMTEXT NULL");
    }
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
