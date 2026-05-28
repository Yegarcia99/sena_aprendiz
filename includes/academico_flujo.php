<?php
// Utilidades del flujo academico: eventos, roles destino y notificaciones.

require_once __DIR__ . '/notificaciones.php';

function instructorIdActual(PDO $db): int {
    $user = getCurrentUser();
    if (($user['rol'] ?? '') !== 'Instructor') {
        return 0;
    }
    $stmt = $db->prepare("SELECT id FROM instructores WHERE usuario_id=? AND activo=1");
    $stmt->execute([(int)($user['id'] ?? 0)]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function infoCasoAcademico(PDO $db, int $pendienteId): ?array {
    $stmt = $db->prepare("
        SELECT pa.*,
               CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre,
               a.email AS aprendiz_email,
               a.usuario_id AS aprendiz_usuario_id,
               f.numero_ficha,
               f.gestor_id AS ficha_gestor_id,
               c.nombre AS competencia_nombre,
               CONCAT(i.nombres,' ',i.apellidos) AS instructor_nombre
        FROM pendientes_aprendices pa
        JOIN aprendices a ON a.id = pa.aprendiz_id
        JOIN fichas f ON f.id = a.ficha_id
        JOIN competencias c ON c.id = pa.competencia_id
        JOIN instructores i ON i.id = pa.instructor_id
        WHERE pa.id = ?
        LIMIT 1
    ");
    $stmt->execute([$pendienteId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function registrarEventoAcademico(
    PDO $db,
    int $pendienteId,
    int $aprendizId,
    string $tipoEvento,
    string $estadoNuevo,
    string $descripcion = '',
    ?string $estadoAnterior = null,
    int $instancia = 0,
    ?string $fechaLimite = null,
    int $accionId = 0,
    int $planId = 0
): void {
    $stmt = $db->prepare("
        INSERT INTO academico_flujo_eventos
        (pendiente_id, aprendiz_id, accion_id, plan_id, tipo_evento, estado_anterior, estado_nuevo, instancia, descripcion, fecha_limite, creado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $pendienteId,
        $aprendizId,
        $accionId ?: null,
        $planId ?: null,
        $tipoEvento,
        $estadoAnterior,
        $estadoNuevo,
        $instancia,
        $descripcion ?: null,
        $fechaLimite ?: null,
        (int)(getCurrentUser()['id'] ?? 0) ?: null,
    ]);

    if (function_exists('registrarAuditoria')) {
        registrarAuditoria($db, [
            'modulo' => 'Academico',
            'accion' => $tipoEvento,
            'entidad_tipo' => $accionId ? 'accion_remedial' : 'pendiente_academico',
            'entidad_id' => $accionId ?: $pendienteId,
            'aprendiz_id' => $aprendizId,
            'pendiente_id' => $pendienteId,
            'descripcion' => $descripcion,
            'valor_anterior' => $estadoAnterior,
            'valor_nuevo' => $estadoNuevo,
        ]);
    }
}

function crearNotificacionInterna(
    PDO $db,
    int $aprendizId,
    ?int $usuarioId,
    int $pendienteId,
    string $referenciaTipo,
    string $asunto,
    string $mensaje,
    string $correoDestino = ''
): void {
    $stmt = $db->prepare("
        INSERT INTO notificaciones
        (aprendiz_id, usuario_id, pendiente_id, referencia_tipo, referencia_id, correo_destino, asunto, mensaje, estado_envio, enviado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $aprendizId,
        $usuarioId ?: null,
        $pendienteId,
        $referenciaTipo,
        $pendienteId,
        $correoDestino,
        $asunto,
        $mensaje,
        'Registrada',
        (int)(getCurrentUser()['id'] ?? 0) ?: null,
    ]);
}

function notificarReporteAcademico(PDO $db, int $pendienteId): void {
    $info = infoCasoAcademico($db, $pendienteId);
    if (!$info) return;

    $asunto = 'Nuevo reporte academico: competencia no aprobada';
    $mensaje = "Se registro un pendiente academico para {$info['aprendiz_nombre']}.\n"
        . "Ficha: {$info['numero_ficha']}\n"
        . "Competencia: {$info['competencia_nombre']}\n"
        . "Instructor: {$info['instructor_nombre']}\n"
        . "Motivo: " . ($info['motivo'] ?: 'Sin detalle.');

    crearNotificacionInterna(
        $db,
        (int)$info['aprendiz_id'],
        (int)$info['aprendiz_usuario_id'],
        $pendienteId,
        'Academico',
        $asunto,
        $mensaje,
        $info['aprendiz_email'] ?? ''
    );

    $gestorId = (int)($info['gestor_id'] ?: $info['ficha_gestor_id']);
    if ($gestorId) {
        crearNotificacionInterna(
            $db,
            (int)$info['aprendiz_id'],
            $gestorId,
            $pendienteId,
            'Academico',
            'Nuevo caso academico en su grupo',
            $mensaje
        );
    }
}

function notificarAccionRemedial(PDO $db, int $pendienteId, int $accionId, ?string $fechaLimite): void {
    $info = infoCasoAcademico($db, $pendienteId);
    if (!$info) return;

    $limiteTexto = $fechaLimite ? date('d/m/Y', strtotime($fechaLimite)) : 'sin fecha limite registrada';
    $asunto = 'Accion remedial asignada';
    $mensaje = "Tiene una accion remedial pendiente para la competencia {$info['competencia_nombre']}.\n"
        . "Fecha limite: {$limiteTexto}.\n"
        . "Revise Mis Pendientes y presente la evidencia solicitada.";

    crearNotificacionInterna(
        $db,
        (int)$info['aprendiz_id'],
        (int)$info['aprendiz_usuario_id'],
        $pendienteId,
        'Accion',
        $asunto,
        $mensaje,
        $info['aprendiz_email'] ?? ''
    );
}

function notificarInstanciaAcademica(PDO $db, int $pendienteId, int $instancia, string $observacion = ''): void {
    $info = infoCasoAcademico($db, $pendienteId);
    if (!$info) return;

    $nombreInstancia = $instancia === 1 ? 'primera instancia' : 'segunda instancia';
    $asunto = 'Proceso academico en ' . $nombreInstancia;
    $mensaje = "Su caso academico paso a {$nombreInstancia} para la competencia {$info['competencia_nombre']}.\n"
        . "Debe presentar los pendientes y evidencias solicitadas.\n"
        . ($observacion ? "Observacion: {$observacion}" : '');

    crearNotificacionInterna(
        $db,
        (int)$info['aprendiz_id'],
        (int)$info['aprendiz_usuario_id'],
        $pendienteId,
        'Plan',
        $asunto,
        $mensaje,
        $info['aprendiz_email'] ?? ''
    );
}

function notificarCasoListoComite(PDO $db, int $pendienteId, string $motivo = ''): void {
    $info = infoCasoAcademico($db, $pendienteId);
    if (!$info) return;

    $mensaje = "El caso academico de {$info['aprendiz_nombre']} quedo listo para comite.\n"
        . "Ficha: {$info['numero_ficha']}\n"
        . "Competencia: {$info['competencia_nombre']}\n"
        . ($motivo ? "Motivo: {$motivo}" : '');

    crearNotificacionInterna(
        $db,
        (int)$info['aprendiz_id'],
        (int)($info['gestor_id'] ?: $info['ficha_gestor_id']),
        $pendienteId,
        'Comité',
        'Caso academico listo para comite',
        $mensaje
    );

    $coords = $db->query("SELECT id FROM usuarios WHERE rol IN ('Coordinador','Administrador') AND activo=1")->fetchAll();
    foreach ($coords as $coord) {
        crearNotificacionInterna(
            $db,
            (int)$info['aprendiz_id'],
            (int)$coord['id'],
            $pendienteId,
            'Comité',
            'Caso academico listo para comite',
            $mensaje
        );
    }
}

function notificarRevisionEvidencia(PDO $db, int $pendienteId, string $estadoRevision, string $observacion = ''): void {
    $info = infoCasoAcademico($db, $pendienteId);
    if (!$info) return;

    $asunto = 'Revision de evidencia: ' . $estadoRevision;
    $mensaje = "La evidencia enviada para la competencia {$info['competencia_nombre']} fue marcada como: {$estadoRevision}.\n"
        . ($observacion ? "Observacion del instructor: {$observacion}" : '');

    crearNotificacionInterna(
        $db,
        (int)$info['aprendiz_id'],
        (int)$info['aprendiz_usuario_id'],
        $pendienteId,
        'Accion',
        $asunto,
        $mensaje,
        $info['aprendiz_email'] ?? ''
    );
}
