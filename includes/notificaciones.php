<?php
// includes/notificaciones.php
// Campanita y alertas — usa la tabla notificaciones ya creada por expediente_schema.php

/**
 * Crea una notificación interna para un usuario del sistema (coordinador/admin).
 * Opcionalmente intenta enviar correo.
 */
function crearAlertaUsuario(
    PDO    $db,
    int    $aprendizId,
    string $asunto,
    string $mensaje,
    string $emailDestino  = '',
    int    $pendienteId   = 0,
    string $referenciaTipo = 'Sistema',
    int    $referenciaId  = 0,
    int    $enviadoPor    = 0
): void {
    $stmt = $db->prepare("
        INSERT INTO notificaciones
        (aprendiz_id, pendiente_id, referencia_tipo, referencia_id,
         correo_destino, asunto, mensaje, estado_envio, enviado_por)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $aprendizId,
        $pendienteId   ?: null,
        $referenciaTipo,
        $referenciaId  ?: null,
        $emailDestino,
        $asunto,
        $mensaje,
        'Registrada',
        $enviadoPor    ?: null,
    ]);

    // Intentar enviar correo si hay destino
    if ($emailDestino) {
        $enviado = _enviarCorreoSena($emailDestino, $asunto, $mensaje);
        if ($enviado) {
            $id = $db->lastInsertId();
            $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE id=?")
               ->execute([$id]);
        }
    }
}

/**
 * Envía correo HTML usando mail() nativo.
 * Para producción reemplazar con PHPMailer + SMTP.
 */
function _enviarCorreoSena(string $para, string $asunto, string $cuerpo): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: SENA Seguimiento <no-reply@sena.edu.co>\r\n";
    $html = "
    <html><body style='font-family:Arial,sans-serif;background:#f0f4f2;padding:24px'>
    <div style='max-width:600px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(39,174,96,.12)'>
        <div style='background:linear-gradient(135deg,#27AE60,#1E8449);padding:22px 28px'>
            <h2 style='color:white;margin:0;font-size:17px'>🎓 SENA — Seguimiento de Aprendices</h2>
        </div>
        <div style='padding:28px'>
            <h3 style='color:#1a2e22;margin-top:0'>" . htmlspecialchars($asunto) . "</h3>
            <p style='color:#5a7a65;line-height:1.7'>" . nl2br(htmlspecialchars($cuerpo)) . "</p>
        </div>
        <div style='background:#f0f4f2;padding:14px 28px;font-size:11px;color:#5a7a65'>
            Mensaje automático del sistema SENA. No responder este correo.
        </div>
    </div></body></html>";
    return @mail($para, $asunto, $html, $headers);
}

/**
 * Retorna alertas recientes NO leídas de todos los aprendices
 * (para coordinadores/admins — se muestran en la campanita).
 * "No leída" = estado_envio = 'Registrada'
 */
function getAlertasPendientes(PDO $db): array {
    try {
        return $db->query("
            SELECT n.*,
                   CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre
            FROM notificaciones n
            LEFT JOIN aprendices a ON a.id = n.aprendiz_id
            WHERE n.referencia_tipo IN ('Comité','Sistema','Expediente','Accion','Plan')
            ORDER BY n.fecha_envio DESC
            LIMIT 15
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Cuenta alertas del sistema no leídas (estado Registrada).
 */
function contarAlertasNuevas(PDO $db): int {
    try {
        return (int)$db->query("
            SELECT COUNT(*) FROM notificaciones
            WHERE estado_envio = 'Registrada'
            AND referencia_tipo IN ('Comité','Sistema','Expediente','Accion','Plan')
        ")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
