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
