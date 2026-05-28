<?php
// includes/notificaciones.php
// Sistema de notificaciones — PHPMailer + SMTP configurable
// Configuración en: includes/mail_config.php

require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';
require_once __DIR__ . '/../libs/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ============================================================
//  ENVÍO DE CORREO — función base con PHPMailer
// ============================================================
function _enviarCorreoSena(
    string $para,
    string $asunto,
    string $cuerpoHtml,
    string $nombreDestinatario = '',
    string $cc = ''
): bool {
    if (!MAIL_ACTIVO) return false;
    if (!$para || !filter_var($para, FILTER_VALIDATE_EMAIL)) return false;

    $mail = new PHPMailer(true);
    try {
        // Servidor
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // Remitente y destinatario
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($para, $nombreDestinatario);

        // CC opcional (coordinador)
        if ($cc && filter_var($cc, FILTER_VALIDATE_EMAIL) && $cc !== $para) {
            $mail->addCC($cc, 'Coordinación SENA');
        }

        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpoHtml;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $cuerpoHtml));

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[SENA-MAIL] Error enviando a ' . $para . ': ' . $mail->ErrorInfo);
        return false;
    }
}

// ============================================================
//  TEMPLATE BASE del correo
// ============================================================
function _templateCorreo(string $titulo, string $cuerpo): string {
    $url = MAIL_SISTEMA_URL;
    return "
<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#e8f5ec;font-family:Inter,Arial,sans-serif'>
  <div style='max-width:600px;margin:32px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(20,80,40,.13)'>

    <!-- Header -->
    <div style='background:linear-gradient(135deg,#1a4a2e 0%,#2e8b57 100%);padding:28px 32px;text-align:center'>
      <div style='display:inline-block;background:rgba(255,255,255,.15);border-radius:12px;padding:10px 18px;margin-bottom:10px'>
        <span style='color:#fff;font-size:22px;font-weight:800;letter-spacing:3px'>SENA</span>
      </div>
      <div style='color:rgba(255,255,255,.85);font-size:13px;margin-top:4px'>Sistema de Seguimiento de Aprendices</div>
    </div>

    <!-- Título -->
    <div style='background:#f5faf7;padding:20px 32px;border-bottom:1px solid #e0ede6'>
      <h2 style='margin:0;color:#1a4a2e;font-size:18px;font-weight:700'>{$titulo}</h2>
    </div>

    <!-- Cuerpo -->
    <div style='padding:28px 32px;color:#2c3e35;line-height:1.7;font-size:14px'>
      {$cuerpo}
    </div>

    <!-- Footer -->
    <div style='background:#f5faf7;padding:16px 32px;border-top:1px solid #e0ede6;text-align:center'>
      <p style='margin:0;color:#7a9e8a;font-size:11px'>
        Mensaje automático del sistema SENA · No responder este correo<br>
        <a href='{$url}' style='color:#2e8b57'>{$url}</a>
      </p>
    </div>
  </div>
</body>
</html>";
}

// ============================================================
//  CORREO DE BIENVENIDA — Aprendiz nuevo
// ============================================================
function enviarBienvenidaAprendiz(
    PDO    $db,
    int    $aprendizId,
    string $nombres,
    string $apellidos,
    string $documento,
    string $emailAprendiz,
    string $ficha,
    string $programa,
    int    $registradoPor = 0
): void {
    $nombreCompleto = trim($nombres . ' ' . $apellidos);
    $url = MAIL_SISTEMA_URL;

    // ── Correo al aprendiz ──────────────────────────────────
    if ($emailAprendiz && filter_var($emailAprendiz, FILTER_VALIDATE_EMAIL)) {
        $cuerpoAprendiz = "
        <p>Hola <strong>{$nombreCompleto}</strong>,</p>
        <p>Tu cuenta en el <strong>Sistema de Seguimiento de Aprendices del SENA</strong> ha sido creada exitosamente.</p>

        <div style='background:#f0f8f3;border:1px solid #b5d9c5;border-radius:10px;padding:18px 22px;margin:20px 0'>
          <p style='margin:0 0 10px;font-weight:700;color:#1a4a2e;font-size:15px'>🔐 Tus credenciales de acceso</p>
          <table style='width:100%;border-collapse:collapse'>
            <tr>
              <td style='padding:7px 0;color:#6b8f7a;font-size:13px;width:130px'>Usuario</td>
              <td style='padding:7px 0;font-weight:700;color:#1a2e22;font-size:15px;letter-spacing:1px'>{$documento}</td>
            </tr>
            <tr>
              <td style='padding:7px 0;color:#6b8f7a;font-size:13px'>Contraseña</td>
              <td style='padding:7px 0;font-weight:700;color:#1a2e22;font-size:15px'>sena{$documento}</td>
            </tr>
            <tr>
              <td style='padding:7px 0;color:#6b8f7a;font-size:13px'>Ficha</td>
              <td style='padding:7px 0;color:#1a2e22'>{$ficha}</td>
            </tr>
            <tr>
              <td style='padding:7px 0;color:#6b8f7a;font-size:13px'>Programa</td>
              <td style='padding:7px 0;color:#1a2e22'>{$programa}</td>
            </tr>
          </table>
        </div>

        <div style='background:#fff8f0;border:1px solid #f0c4a0;border-radius:8px;padding:14px 18px;margin:16px 0'>
          <p style='margin:0;color:#8a4a00;font-size:13px'>
            ⚠️ <strong>Por seguridad</strong>, te recomendamos cambiar tu contraseña en tu primer ingreso.
            Ingresa al sistema, ve a <em>Mi Perfil</em> y actualiza tu contraseña.
          </p>
        </div>

        <p style='text-align:center;margin:24px 0'>
          <a href='{$url}' style='display:inline-block;background:#2e8b57;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;font-size:14px'>
            Ingresar al sistema →
          </a>
        </p>
        <p style='color:#7a9e8a;font-size:12px'>Si tienes problemas para ingresar, comunícate con tu coordinador o instructor.</p>";

        // Buscar usuario_id del aprendiz para vincular la notificación
        $stmtUid = $db->prepare("SELECT usuario_id FROM aprendices WHERE id = ?");
        $stmtUid->execute([$aprendizId]);
        $uidAprendiz = (int)($stmtUid->fetchColumn() ?: 0);

        $html = _templateCorreo('Bienvenido al Sistema SENA', $cuerpoAprendiz);
        $enviado = _enviarCorreoSena(
            $emailAprendiz,
            'Bienvenido al Sistema de Seguimiento SENA — Tus credenciales de acceso',
            $html,
            $nombreCompleto,
            MAIL_COORDINADOR
        );

        // Registrar en BD vinculando al usuario del aprendiz
        _registrarNotificacionBD($db, $aprendizId, 0,
            'Bienvenida al sistema',
            'Bienvenido al Sistema de Seguimiento SENA — Tus credenciales de acceso',
            "Cuenta creada para {$nombreCompleto} (Doc: {$documento}). Contraseña inicial: sena{$documento}",
            $emailAprendiz,
            $enviado ? 'Enviada' : 'Error',
            $registradoPor,
            $uidAprendiz
        );
    }

    // ── Correo al coordinador ───────────────────────────────
    $emailCoord = MAIL_COORDINADOR;
    if ($emailCoord && filter_var($emailCoord, FILTER_VALIDATE_EMAIL)) {
        $tieneCorreo = $emailAprendiz ? "✅ {$emailAprendiz}" : '❌ Sin correo registrado';
        $cuerpoCoord = "
        <p>Se ha registrado un nuevo aprendiz en el sistema:</p>

        <div style='background:#f0f8f3;border:1px solid #b5d9c5;border-radius:10px;padding:18px 22px;margin:20px 0'>
          <table style='width:100%;border-collapse:collapse'>
            <tr><td style='padding:6px 0;color:#6b8f7a;font-size:13px;width:140px'>Nombre</td><td style='padding:6px 0;font-weight:600;color:#1a2e22'>{$nombreCompleto}</td></tr>
            <tr><td style='padding:6px 0;color:#6b8f7a;font-size:13px'>Documento</td><td style='padding:6px 0;color:#1a2e22'>{$documento}</td></tr>
            <tr><td style='padding:6px 0;color:#6b8f7a;font-size:13px'>Ficha</td><td style='padding:6px 0;color:#1a2e22'>{$ficha}</td></tr>
            <tr><td style='padding:6px 0;color:#6b8f7a;font-size:13px'>Programa</td><td style='padding:6px 0;color:#1a2e22'>{$programa}</td></tr>
            <tr><td style='padding:6px 0;color:#6b8f7a;font-size:13px'>Correo</td><td style='padding:6px 0;color:#1a2e22'>{$tieneCorreo}</td></tr>
            <tr><td style='padding:6px 0;color:#6b8f7a;font-size:13px'>Contraseña inicial</td><td style='padding:6px 0;font-weight:700;color:#1a2e22'>sena{$documento}</td></tr>
          </table>
        </div>

        " . ($emailAprendiz
            ? "<p style='color:#2e8b57'>✅ Se envió correo de bienvenida con credenciales al aprendiz.</p>"
            : "<p style='color:#c8960c'>⚠️ El aprendiz no tiene correo registrado — no se pudo enviar bienvenida.</p>") . "

        <p style='text-align:center;margin:24px 0'>
          <a href='{$url}' style='display:inline-block;background:#2e8b57;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;font-size:14px'>
            Ver en el sistema →
          </a>
        </p>";

        $htmlCoord = _templateCorreo('Nuevo aprendiz registrado', $cuerpoCoord);
        $enviadoCoord = _enviarCorreoSena(
            $emailCoord,
            "Nuevo aprendiz registrado: {$nombreCompleto}",
            $htmlCoord,
            'Coordinación'
        );

        _registrarNotificacionBD($db, $aprendizId, 0,
            'Notificación coordinador',
            "Nuevo aprendiz registrado: {$nombreCompleto}",
            "Aprendiz {$nombreCompleto} (Doc: {$documento}) registrado en ficha {$ficha}.",
            $emailCoord,
            $enviadoCoord ? 'Enviada' : 'Error',
            $registradoPor
        );
    }
}

// ============================================================
//  CORREO DE BIENVENIDA — Cargue masivo (resumen)
// ============================================================
function enviarResumenCargueMasivo(
    string $emailCoord,
    int    $insertados,
    int    $actualizados,
    array  $nuevos,   // [['nombre'=>'...','documento'=>'...','email'=>'...','ficha'=>'...']]
    array  $errores
): void {
    if (!$emailCoord || !filter_var($emailCoord, FILTER_VALIDATE_EMAIL)) return;

    $url = MAIL_SISTEMA_URL;
    $filas = '';
    foreach ($nuevos as $n) {
        $correoMostrar = $n['email'] ? "✅ {$n['email']}" : '❌ Sin correo';
        $filas .= "
        <tr>
          <td style='padding:8px 10px;border-bottom:1px solid #e8f0eb;color:#1a2e22'>{$n['nombre']}</td>
          <td style='padding:8px 10px;border-bottom:1px solid #e8f0eb;color:#1a2e22'>{$n['documento']}</td>
          <td style='padding:8px 10px;border-bottom:1px solid #e8f0eb;color:#1a2e22'>{$n['ficha']}</td>
          <td style='padding:8px 10px;border-bottom:1px solid #e8f0eb;font-size:12px;color:#5a7a65'>{$correoMostrar}</td>
        </tr>";
    }

    $tablaErrores = '';
    if (!empty($errores)) {
        $tablaErrores = "<div style='background:#fdf0ee;border:1px solid #f0c4c0;border-radius:8px;padding:14px 18px;margin-top:16px'>
            <p style='margin:0 0 8px;font-weight:700;color:#8b2a20'>⚠️ " . count($errores) . " fila(s) con errores:</p>
            <ul style='margin:0;padding-left:18px;color:#8b2a20;font-size:13px'>"
            . implode('', array_map(fn($e) => "<li>{$e}</li>", $errores))
            . "</ul></div>";
    }

    $cuerpo = "
    <p>Se completó el cargue masivo de aprendices con los siguientes resultados:</p>

    <div style='display:flex;gap:12px;margin:20px 0'>
      <div style='flex:1;background:#f0f8f3;border:1px solid #b5d9c5;border-radius:10px;padding:16px;text-align:center'>
        <div style='font-size:28px;font-weight:800;color:#1a4a2e'>{$insertados}</div>
        <div style='color:#6b8f7a;font-size:12px;margin-top:4px'>NUEVOS REGISTRADOS</div>
      </div>
      <div style='flex:1;background:#eaf2fb;border:1px solid #9ac0e0;border-radius:10px;padding:16px;text-align:center'>
        <div style='font-size:28px;font-weight:800;color:#1a4f72'>{$actualizados}</div>
        <div style='color:#6b8f7a;font-size:12px;margin-top:4px'>ACTUALIZADOS</div>
      </div>
    </div>

    " . ($filas ? "
    <p style='font-weight:700;color:#1a4a2e;margin-bottom:8px'>Aprendices nuevos registrados:</p>
    <div style='overflow-x:auto'>
    <table style='width:100%;border-collapse:collapse;font-size:13px'>
      <thead>
        <tr style='background:#f5faf7'>
          <th style='padding:8px 10px;text-align:left;color:#6b8f7a;font-size:11px;text-transform:uppercase;letter-spacing:.5px'>Nombre</th>
          <th style='padding:8px 10px;text-align:left;color:#6b8f7a;font-size:11px;text-transform:uppercase;letter-spacing:.5px'>Documento</th>
          <th style='padding:8px 10px;text-align:left;color:#6b8f7a;font-size:11px;text-transform:uppercase;letter-spacing:.5px'>Ficha</th>
          <th style='padding:8px 10px;text-align:left;color:#6b8f7a;font-size:11px;text-transform:uppercase;letter-spacing:.5px'>Correo</th>
        </tr>
      </thead>
      <tbody>{$filas}</tbody>
    </table>
    </div>" : '') . "

    {$tablaErrores}

    <p style='color:#7a9e8a;font-size:12px;margin-top:20px'>
      💡 Los aprendices con correo registrado recibieron sus credenciales de acceso individualmente.<br>
      La contraseña inicial de cada aprendiz es: <strong>sena + número de documento</strong>
    </p>

    <p style='text-align:center;margin:24px 0'>
      <a href='{$url}' style='display:inline-block;background:#2e8b57;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;font-size:14px'>
        Ver aprendices en el sistema →
      </a>
    </p>";

    $html = _templateCorreo("Cargue masivo completado — {$insertados} nuevos aprendices", $cuerpo);
    _enviarCorreoSena(
        $emailCoord,
        "Cargue masivo completado: {$insertados} nuevos, {$actualizados} actualizados",
        $html,
        'Coordinación'
    );
}

// ============================================================
//  Registro en BD
// ============================================================
function _registrarNotificacionBD(
    PDO    $db,
    int    $aprendizId,
    int    $pendienteId,
    string $referenciaTipo,
    string $asunto,
    string $mensaje,
    string $correoDestino,
    string $estadoEnvio,
    int    $enviadoPor,
    int    $usuarioId = 0
): void {
    try {
        $db->prepare("
            INSERT INTO notificaciones
            (aprendiz_id, usuario_id, pendiente_id, referencia_tipo, referencia_id,
             correo_destino, asunto, mensaje, estado_envio, enviado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $aprendizId ?: null,
            $usuarioId  ?: null,
            $pendienteId ?: null,
            $referenciaTipo,
            null,
            $correoDestino,
            $asunto,
            $mensaje,
            $estadoEnvio,
            $enviadoPor ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('[SENA-MAIL] Error registrando notificación BD: ' . $e->getMessage());
    }
}

// ============================================================
//  Función genérica — otros módulos del sistema
// ============================================================
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
    $enviado = false;
    if ($emailDestino) {
        $html    = _templateCorreo($asunto, '<p>' . nl2br(htmlspecialchars($mensaje)) . '</p>');
        $enviado = _enviarCorreoSena($emailDestino, $asunto, $html);
    }
    _registrarNotificacionBD($db, $aprendizId, $pendienteId,
        $referenciaTipo, $asunto, $mensaje, $emailDestino,
        $enviado ? 'Enviada' : 'Registrada', $enviadoPor
    );
}

// ============================================================
//  Campanita — alertas internas
// ============================================================
function getAlertasPendientes(PDO $db): array {
    $user   = getCurrentUser();
    $uid    = (int)($user['id'] ?? 0);
    $rol    = $user['rol'] ?? '';

    // Aprendiz: solo ve sus propias notificaciones
    // Otros roles: ven las notificaciones sin usuario_id (sistema/coordinador) y las propias
    try {
        if ($rol === 'Aprendiz') {
            $stmt = $db->prepare("
                SELECT n.*,
                       CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre
                FROM notificaciones n
                LEFT JOIN aprendices a ON a.id = n.aprendiz_id
                WHERE n.usuario_id = ?
                ORDER BY n.fecha_envio DESC
                LIMIT 15
            ");
            $stmt->execute([$uid]);
        } else {
            $stmt = $db->prepare("
                SELECT n.*,
                       CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre
                FROM notificaciones n
                LEFT JOIN aprendices a ON a.id = n.aprendiz_id
                WHERE (n.usuario_id = ? OR n.usuario_id IS NULL)
                AND n.referencia_tipo IN ('Comité','Sistema','Expediente','Academico','Accion','Plan','Bienvenida al sistema','Notificación coordinador')
                ORDER BY n.fecha_envio DESC
                LIMIT 15
            ");
            $stmt->execute([$uid]);
        }
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function contarAlertasNuevas(PDO $db): int {
    $user = getCurrentUser();
    $uid  = (int)($user['id'] ?? 0);
    $rol  = $user['rol'] ?? '';

    try {
        if ($rol === 'Aprendiz') {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM notificaciones
                WHERE estado_envio = 'Registrada'
                AND usuario_id = ?
            ");
            $stmt->execute([$uid]);
        } else {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM notificaciones
                WHERE estado_envio = 'Registrada'
                AND (usuario_id = ? OR usuario_id IS NULL)
                AND referencia_tipo IN ('Comité','Sistema','Expediente','Academico','Accion','Plan')
            ");
            $stmt->execute([$uid]);
        }
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
