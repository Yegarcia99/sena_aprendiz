<?php
// includes/disciplinario_schema.php
// Tablas y utilidades del módulo disciplinario.
// Se autocrea igual que expediente_schema.php — sin ejecutar SQL manual.

function ensureDisciplinarioSchema(PDO $db): void {

    // ── 1. Hechos disciplinarios ─────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS disc_hechos (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            aprendiz_id     INT NOT NULL,
            ficha_id        INT NULL,
            gestor_id       INT NOT NULL COMMENT 'Usuario que registra (gestor del grupo)',
            fecha_hecho     DATE NOT NULL,
            tipo_hecho      VARCHAR(80) NOT NULL
                COMMENT 'Inasistencia injustificada|Agresión verbal|Agresión física|Incumplimiento de normas|Comportamiento inadecuado|Uso indebido de dispositivos|Fraude académico|Otro',
            descripcion     TEXT NOT NULL,
            lugar           VARCHAR(120) NULL,
            testigos        TEXT NULL,
            estado          ENUM('Abierto','En atención','Comprometido','Cerrado','Remitido a comité')
                            NOT NULL DEFAULT 'Abierto',
            remitir_comite  TINYINT(1) NOT NULL DEFAULT 0,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_dh_aprendiz (aprendiz_id),
            INDEX idx_dh_gestor   (gestor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── 2. Atenciones / descargos / compromisos ──────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS disc_atenciones (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            hecho_id            INT NOT NULL,
            aprendiz_id         INT NOT NULL,
            gestor_id           INT NOT NULL,
            instructor_id       INT NULL,
            fecha_citacion      DATE NOT NULL,
            tipo_atencion       ENUM('Llamado de atención verbal',
                                     'Llamado de atención escrito',
                                     'Descargos del aprendiz',
                                     'Compromiso de mejora',
                                     'Remisión a bienestar',
                                     'Otro') NOT NULL DEFAULT 'Llamado de atención verbal',
            descripcion         TEXT NOT NULL,
            descargos_aprendiz  TEXT NULL COMMENT 'Versión del aprendiz',
            compromisos         TEXT NULL,
            fecha_seguimiento   DATE NULL,
            resultado           ENUM('Pendiente','Cumplido','Incumplido','Reincidencia') NOT NULL DEFAULT 'Pendiente',
            firma_instructor    MEDIUMTEXT NULL,
            firma_aprendiz      MEDIUMTEXT NULL,
            firma_gestor        MEDIUMTEXT NULL,
            archivo_ruta        VARCHAR(255) NULL,
            archivo_nombre      VARCHAR(255) NULL,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_da_hecho    (hecho_id),
            INDEX idx_da_aprendiz (aprendiz_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── 3. Seguimiento de reincidencias ──────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS disc_seguimiento (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            hecho_id      INT NOT NULL,
            aprendiz_id   INT NOT NULL,
            gestor_id     INT NOT NULL,
            fecha         DATE NOT NULL,
            observacion   TEXT NOT NULL,
            resultado     ENUM('Cumpliendo','Reincidencia','Cerrado') NOT NULL DEFAULT 'Cumpliendo',
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ds_hecho (hecho_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (function_exists('columnExists')) {
        if (!columnExists($db, 'disc_hechos', 'gravedad')) {
            $db->exec("ALTER TABLE disc_hechos ADD COLUMN gravedad VARCHAR(40) NOT NULL DEFAULT 'Falta leve' AFTER tipo_hecho");
        }
        if (!columnExists($db, 'disc_hechos', 'estado_flujo')) {
            $db->exec("ALTER TABLE disc_hechos ADD COLUMN estado_flujo VARCHAR(60) NOT NULL DEFAULT 'Reportado' AFTER estado");
        }
        if (!columnExists($db, 'disc_hechos', 'fecha_limite_atencion')) {
            $db->exec("ALTER TABLE disc_hechos ADD COLUMN fecha_limite_atencion DATE NULL AFTER fecha_hecho");
        }
        if (!columnExists($db, 'disc_hechos', 'fecha_cierre')) {
            $db->exec("ALTER TABLE disc_hechos ADD COLUMN fecha_cierre DATETIME NULL AFTER remitir_comite");
        }
        if (!columnExists($db, 'disc_hechos', 'motivo_cierre')) {
            $db->exec("ALTER TABLE disc_hechos ADD COLUMN motivo_cierre TEXT NULL AFTER fecha_cierre");
        }
        if (!columnExists($db, 'disc_hechos', 'instancia_actual')) {
            $db->exec("ALTER TABLE disc_hechos ADD COLUMN instancia_actual TINYINT(1) NOT NULL DEFAULT 0 AFTER estado_flujo");
        }
        if (!columnExists($db, 'disc_hechos', 'fecha_primera_instancia')) {
            $db->exec("ALTER TABLE disc_hechos ADD COLUMN fecha_primera_instancia DATE NULL AFTER instancia_actual");
        }
        if (!columnExists($db, 'disc_hechos', 'fecha_segunda_instancia')) {
            $db->exec("ALTER TABLE disc_hechos ADD COLUMN fecha_segunda_instancia DATE NULL AFTER fecha_primera_instancia");
        }
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS disc_flujo_eventos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hecho_id INT NOT NULL,
            aprendiz_id INT NOT NULL,
            atencion_id INT NULL,
            seguimiento_id INT NULL,
            tipo_evento VARCHAR(80) NOT NULL,
            estado_anterior VARCHAR(60) NULL,
            estado_nuevo VARCHAR(60) NOT NULL,
            descripcion TEXT NULL,
            fecha_limite DATE NULL,
            creado_por INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dfe_hecho (hecho_id),
            INDEX idx_dfe_aprendiz (aprendiz_id),
            INDEX idx_dfe_tipo (tipo_evento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS disc_evidencias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hecho_id INT NOT NULL,
            atencion_id INT NULL,
            aprendiz_id INT NOT NULL,
            descripcion TEXT NULL,
            archivo_ruta VARCHAR(255) NOT NULL,
            archivo_nombre VARCHAR(255) NOT NULL,
            estado_revision VARCHAR(40) NOT NULL DEFAULT 'Pendiente',
            observacion_revision TEXT NULL,
            revisado_por INT NULL,
            fecha_revision DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_de_hecho (hecho_id),
            INDEX idx_de_atencion (atencion_id),
            INDEX idx_de_aprendiz (aprendiz_id),
            INDEX idx_de_estado (estado_revision)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function registrarEventoDisciplinario(
    PDO $db,
    int $hechoId,
    int $aprendizId,
    string $tipoEvento,
    string $estadoNuevo,
    string $descripcion = '',
    ?string $estadoAnterior = null,
    ?string $fechaLimite = null,
    int $atencionId = 0,
    int $seguimientoId = 0
): void {
    $stmt = $db->prepare("
        INSERT INTO disc_flujo_eventos
        (hecho_id, aprendiz_id, atencion_id, seguimiento_id, tipo_evento, estado_anterior, estado_nuevo, descripcion, fecha_limite, creado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $hechoId,
        $aprendizId,
        $atencionId ?: null,
        $seguimientoId ?: null,
        $tipoEvento,
        $estadoAnterior,
        $estadoNuevo,
        $descripcion ?: null,
        $fechaLimite ?: null,
        (int)(getCurrentUser()['id'] ?? 0) ?: null,
    ]);

    if (function_exists('registrarAuditoria')) {
        registrarAuditoria($db, [
            'modulo' => 'Disciplinario',
            'accion' => $tipoEvento,
            'entidad_tipo' => 'hecho_disciplinario',
            'entidad_id' => $hechoId,
            'aprendiz_id' => $aprendizId,
            'descripcion' => $descripcion,
            'valor_anterior' => $estadoAnterior,
            'valor_nuevo' => $estadoNuevo,
        ]);
    }
}

function notificarDisciplinario(PDO $db, int $aprendizId, int $hechoId, string $asunto, string $mensaje): void {
    $apr = $db->prepare("
        SELECT a.email, a.usuario_id, f.gestor_id
        FROM aprendices a
        JOIN fichas f ON f.id=a.ficha_id
        WHERE a.id=?
    ");
    $apr->execute([$aprendizId]);
    $info = $apr->fetch();
    if (!$info) return;

    $stmt = $db->prepare("
        INSERT INTO notificaciones
        (aprendiz_id, usuario_id, pendiente_id, referencia_tipo, referencia_id, correo_destino, asunto, mensaje, estado_envio, enviado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    foreach (array_unique(array_filter([(int)$info['usuario_id'], (int)$info['gestor_id']])) as $usuarioDestino) {
        $stmt->execute([
            $aprendizId,
            $usuarioDestino,
            null,
            'Disciplinario',
            $hechoId,
            $info['email'] ?? '',
            $asunto,
            $mensaje,
            'Registrada',
            (int)(getCurrentUser()['id'] ?? 0) ?: null,
        ]);
    }
}

function crearNotificacionDisciplinariaUsuario(PDO $db, int $aprendizId, int $usuarioId, int $hechoId, string $asunto, string $mensaje): void {
    if (!$usuarioId) return;
    $correo = '';
    $mail = $db->prepare("SELECT email FROM aprendices WHERE id=?");
    $mail->execute([$aprendizId]);
    $correo = (string)($mail->fetchColumn() ?: '');

    $stmt = $db->prepare("
        INSERT INTO notificaciones
        (aprendiz_id, usuario_id, pendiente_id, referencia_tipo, referencia_id, correo_destino, asunto, mensaje, estado_envio, enviado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $aprendizId,
        $usuarioId,
        null,
        'Disciplinario',
        $hechoId,
        $correo,
        $asunto,
        $mensaje,
        'Registrada',
        (int)(getCurrentUser()['id'] ?? 0) ?: null,
    ]);
}

function guardarEvidenciaDisciplinaria(PDO $db, array $file, array $data): int {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Seleccione un archivo valido.');
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allow = ['pdf','doc','docx','jpg','jpeg','png','xls','xlsx','zip','rar'];
    if (!in_array($ext, $allow, true)) {
        throw new RuntimeException('Formato no permitido. Use PDF, Word, Excel, imagen, ZIP o RAR.');
    }

    $stored = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $target = disciplinarioUploadDir() . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }

    $ruta = 'uploads/disciplinario/' . $stored;
    $stmt = $db->prepare("
        INSERT INTO disc_evidencias
        (hecho_id, atencion_id, aprendiz_id, descripcion, archivo_ruta, archivo_nombre, estado_revision)
        VALUES (?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        (int)$data['hecho_id'],
        !empty($data['atencion_id']) ? (int)$data['atencion_id'] : null,
        (int)$data['aprendiz_id'],
        trim((string)($data['descripcion'] ?? '')) ?: null,
        $ruta,
        $file['name'] ?? $stored,
        'Pendiente',
    ]);

    return (int)$db->lastInsertId();
}

function evaluarEscalamientoDisciplinario(PDO $db, int $aprendizId, int $hechoId, string $gravedad, string $tipoHecho = ''): void {
    $totalStmt = $db->prepare("
        SELECT COUNT(*)
        FROM disc_hechos
        WHERE aprendiz_id=?
          AND estado <> 'Cerrado'
          AND remitir_comite = 0
    ");
    $totalStmt->execute([$aprendizId]);
    $totalFaltas = (int)$totalStmt->fetchColumn();

    $maxStmt = $db->prepare("SELECT COALESCE(MAX(instancia_actual),0) FROM disc_hechos WHERE aprendiz_id=?");
    $maxStmt->execute([$aprendizId]);
    $instanciaPrevia = (int)$maxStmt->fetchColumn();

    $textoGrave = mb_strtolower($gravedad . ' ' . $tipoHecho, 'UTF-8');
    $esGrave = str_contains($textoGrave, 'grave')
        || str_contains($textoGrave, 'gravisima')
        || str_contains($textoGrave, 'agresi')
        || str_contains($textoGrave, 'excepcional');

    $nuevoFlujo = null;
    $nuevaInstancia = 0;
    $remitirComite = 0;

    if ($totalFaltas >= 4 || ($esGrave && $instanciaPrevia >= 2)) {
        $nuevoFlujo = 'Listo para comite';
        $nuevaInstancia = 2;
        $remitirComite = 1;
    } elseif ($totalFaltas >= 3 || ($esGrave && $instanciaPrevia >= 1)) {
        $nuevoFlujo = 'Segunda instancia';
        $nuevaInstancia = 2;
    } elseif ($totalFaltas >= 2 || $esGrave) {
        $nuevoFlujo = 'Primera instancia';
        $nuevaInstancia = 1;
    }

    if (!$nuevoFlujo) {
        return;
    }

    $db->prepare("
        UPDATE disc_hechos
        SET estado_flujo=?,
            instancia_actual=?,
            fecha_primera_instancia=IF(? >= 1 AND fecha_primera_instancia IS NULL, CURDATE(), fecha_primera_instancia),
            fecha_segunda_instancia=IF(? >= 2 AND fecha_segunda_instancia IS NULL, CURDATE(), fecha_segunda_instancia),
            remitir_comite=GREATEST(remitir_comite, ?)
        WHERE id=?
    ")->execute([$nuevoFlujo, $nuevaInstancia, $nuevaInstancia, $nuevaInstancia, $remitirComite, $hechoId]);

    $detalle = "Escalamiento automatico por reincidencia/gravedad. Total de faltas activas: {$totalFaltas}. Gravedad: {$gravedad}.";
    registrarEventoDisciplinario($db, $hechoId, $aprendizId, $nuevoFlujo, $nuevoFlujo, $detalle, 'Reportado');
    notificarDisciplinario($db, $aprendizId, $hechoId, 'Escalamiento disciplinario: ' . $nuevoFlujo, $detalle);
}

/**
 * Diagnóstico del caso disciplinario — para saber si está listo para comité.
 */
function diagnosticoDisciplinario(PDO $db, int $hechoId): array {
    $atenciones = (int)$db->prepare("SELECT COUNT(*) FROM disc_atenciones WHERE hecho_id=?")
        ->execute([$hechoId]) ? $db->query("SELECT COUNT(*) FROM disc_atenciones WHERE hecho_id=$hechoId")->fetchColumn() : 0;

    $stmt = $db->prepare("SELECT COUNT(*) FROM disc_atenciones WHERE hecho_id=?");
    $stmt->execute([$hechoId]);
    $totalAtenciones = (int)$stmt->fetchColumn();

    $stmt2 = $db->prepare("SELECT COUNT(*) FROM disc_atenciones WHERE hecho_id=? AND firma_aprendiz IS NOT NULL AND firma_aprendiz != ''");
    $stmt2->execute([$hechoId]);
    $firmadasAprendiz = (int)$stmt2->fetchColumn();

    $stmt3 = $db->prepare("SELECT COUNT(*) FROM disc_seguimiento WHERE hecho_id=?");
    $stmt3->execute([$hechoId]);
    $seguimientos = (int)$stmt3->fetchColumn();

    $stmt4 = $db->prepare("SELECT COUNT(*) FROM disc_atenciones WHERE hecho_id=? AND resultado='Reincidencia'");
    $stmt4->execute([$hechoId]);
    $reincidencias = (int)$stmt4->fetchColumn();

    $faltantes = [];
    if ($totalAtenciones === 0) $faltantes[] = 'No hay atención o descargos registrados.';
    if ($firmadasAprendiz === 0) $faltantes[] = 'Ninguna atención tiene firma del aprendiz.';

    return [
        'atenciones'       => $totalAtenciones,
        'firmadas_aprendiz'=> $firmadasAprendiz,
        'seguimientos'     => $seguimientos,
        'reincidencias'    => $reincidencias,
        'faltantes'        => $faltantes,
        'listo_comite'     => empty($faltantes),
    ];
}

/**
 * Directorio de uploads para soportes disciplinarios.
 */
function disciplinarioUploadDir(): string {
    $dir = __DIR__ . '/../uploads/disciplinario';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}
