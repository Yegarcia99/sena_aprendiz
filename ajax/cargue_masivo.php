<?php
// ajax/cargue_masivo.php - Procesador de cargue masivo CSV
require_once __DIR__ . '/../includes/auth.php';
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }
header('Content-Type: application/json; charset=utf-8');

$tipo = $_GET['tipo'] ?? 'aprendices'; // aprendices | instructores | fichas
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método no permitido']); exit;
}

$file = $_FILES['archivo'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No se recibió archivo válido']); exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'])) {
    echo json_encode(['error' => 'Solo se permiten archivos CSV']); exit;
}

$content = file_get_contents($file['tmp_name']);
// Detectar y convertir encoding
if (!mb_check_encoding($content, 'UTF-8')) {
    $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
}

$lines   = array_filter(explode("\n", str_replace("\r\n", "\n", str_replace("\r", "\n", $content))));
$lines   = array_values($lines);
if (count($lines) < 2) { echo json_encode(['error' => 'El archivo está vacío o no tiene datos']); exit; }

// Parsear encabezados (primera fila)
$sep = strpos($lines[0], ';') !== false ? ';' : ',';
$headers = array_map('trim', str_getcsv($lines[0], $sep));
$headers = array_map('mb_strtolower', $headers);

$inserted = 0; $updated = 0; $errors = []; $nuevosParaCorreo = [];

$db->beginTransaction();
try {
    foreach (array_slice($lines, 1) as $lineNum => $line) {
        $line = trim($line);
        if (!$line) continue;
        $row = array_map('trim', str_getcsv($line, $sep));
        $data = array_combine(array_slice($headers, 0, count($row)), $row);
        $rowNum = $lineNum + 2;

        if ($tipo === 'aprendices') {
            $doc = $data['documento'] ?? $data['numero_documento'] ?? '';
            if (!$doc) { $errors[] = "Fila $rowNum: documento vacío"; continue; }

            // Buscar ficha_id por numero_ficha
            $fichaNum = $data['ficha'] ?? $data['numero_ficha'] ?? '';
            $fichaId  = null;
            if ($fichaNum) {
                $sf = $db->prepare("SELECT id FROM fichas WHERE numero_ficha=?");
                $sf->execute([$fichaNum]);
                $fichaId = $sf->fetchColumn();
            }
            if (!$fichaId) { $errors[] = "Fila $rowNum: ficha '$fichaNum' no encontrada"; continue; }

            $nombres   = $data['nombres']   ?? '';
            $apellidos = $data['apellidos'] ?? '';
            $email     = $data['email']     ?? $data['correo'] ?? '';
            $telefono  = $data['telefono']  ?? $data['celular'] ?? '';
            $tipo_doc  = $data['tipo_documento'] ?? $data['tipo_doc'] ?? 'CC';
            $estado    = $data['estado']    ?? 'Activo';

            if (!$nombres || !$apellidos) { $errors[] = "Fila $rowNum: nombres o apellidos vacíos"; continue; }

            $existe = $db->prepare("SELECT id FROM aprendices WHERE documento=?");
            $existe->execute([$doc]);
            if ($existe->fetchColumn()) {
                $db->prepare("UPDATE aprendices SET nombres=?,apellidos=?,email=?,telefono=?,ficha_id=?,estado=?,tipo_documento=? WHERE documento=?")
                   ->execute([$nombres,$apellidos,$email,$telefono,$fichaId,$estado,$tipo_doc,$doc]);
                $updated++;
            } else {
                $db->prepare("INSERT INTO aprendices (nombres,apellidos,documento,tipo_documento,email,telefono,ficha_id,estado) VALUES(?,?,?,?,?,?,?,?)")
                   ->execute([$nombres,$apellidos,$doc,$tipo_doc,$email,$telefono,$fichaId,$estado]);
                $nuevoId = (int)$db->lastInsertId();
                $inserted++;
                // Guardar para enviar correo individual y resumen
                $nuevosParaCorreo[] = ['nombre' => $nombres.' '.$apellidos, 'documento' => $doc, 'email' => $email, 'ficha' => $fichaNum];
                // Correo individual al aprendiz si tiene correo
                if (!empty($email)) {
                    require_once __DIR__ . '/../includes/notificaciones.php';
                    $sfProg = $db->prepare("SELECT p.nombre AS programa FROM fichas f JOIN programas p ON p.id=f.programa_id WHERE f.id=?");
                    $sfProg->execute([$fichaId]);
                    $prog = $sfProg->fetchColumn() ?: '';
                    enviarBienvenidaAprendiz($db, $nuevoId, $nombres, $apellidos, $doc, $email, $fichaNum, $prog, 0);
                }
            }

        } elseif ($tipo === 'instructores') {
            $doc = $data['documento'] ?? '';
            if (!$doc) { $errors[] = "Fila $rowNum: documento vacío"; continue; }
            $nombres   = $data['nombres']   ?? '';
            $apellidos = $data['apellidos'] ?? '';
            $email     = $data['email']     ?? '';
            $telefono  = $data['telefono']  ?? '';
            $tipo_ins  = $data['tipo']      ?? 'Planta';
            if (!$nombres || !$apellidos) { $errors[] = "Fila $rowNum: nombres o apellidos vacíos"; continue; }

            $existe = $db->prepare("SELECT id FROM instructores WHERE documento=?");
            $existe->execute([$doc]);
            if ($existe->fetchColumn()) {
                $db->prepare("UPDATE instructores SET nombres=?,apellidos=?,email=?,telefono=?,tipo=? WHERE documento=?")
                   ->execute([$nombres,$apellidos,$email,$telefono,$tipo_ins,$doc]);
                $updated++;
            } else {
                $db->prepare("INSERT INTO instructores (nombres,apellidos,documento,email,telefono,tipo) VALUES(?,?,?,?,?,?)")
                   ->execute([$nombres,$apellidos,$doc,$email,$telefono,$tipo_ins]);
                $inserted++;
            }
        }
    }
    $db->commit();
    // Enviar resumen al coordinador
    if ($inserted > 0) {
        require_once __DIR__ . '/../includes/notificaciones.php';
        enviarResumenCargueMasivo(MAIL_COORDINADOR, $inserted, $updated, $nuevosParaCorreo, $errors);
    }
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['error' => 'Error en base de datos: ' . $e->getMessage()]); exit;
}

echo json_encode([
    'ok'       => true,
    'inserted' => $inserted,
    'updated'  => $updated,
    'errors'   => $errors,
    'total'    => $inserted + $updated
]);
