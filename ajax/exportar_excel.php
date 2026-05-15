<?php
// ajax/exportar_excel.php - Exportar datos a CSV/Excel
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$tipo = $_GET['tipo'] ?? 'aprendices';
$db   = getDB();

$filename = '';
$headers  = [];
$rows     = [];

switch ($tipo) {
    case 'aprendices':
        $filename = 'aprendices_' . date('Ymd_His') . '.csv';
        $headers  = ['Documento','Tipo Doc','Nombres','Apellidos','Email','Telefono','Ficha','Programa','Estado','Pendientes_Activos'];
        $data = $db->query("
            SELECT a.documento, a.tipo_documento, a.nombres, a.apellidos,
                   a.email, a.telefono, f.numero_ficha, p.nombre AS programa, a.estado,
                   COUNT(pa.id) AS pendientes
            FROM aprendices a
            JOIN fichas f ON f.id=a.ficha_id
            JOIN programas p ON p.id=f.programa_id
            LEFT JOIN pendientes_aprendices pa ON pa.aprendiz_id=a.id AND pa.estado NOT IN('Superado')
            GROUP BY a.id
            ORDER BY a.apellidos, a.nombres
        ")->fetchAll(PDO::FETCH_NUM);
        $rows = $data;
        break;

    case 'pendientes':
        $filename = 'pendientes_' . date('Ymd_His') . '.csv';
        $headers  = ['Fecha_Registro','Aprendiz','Documento','Ficha','Competencia','Instructor','Trimestre','Estado','Motivo','Debe_Repetir'];
        $data = $db->query("
            SELECT pa.fecha_registro,
                   CONCAT(a.nombres,' ',a.apellidos), a.documento, f.numero_ficha,
                   c.nombre, CONCAT(i.nombres,' ',i.apellidos),
                   pa.trimestre_ocurrencia, pa.estado, pa.motivo,
                   IF(pa.debe_repetir_competencia=1,'Sí','No')
            FROM pendientes_aprendices pa
            JOIN aprendices a ON a.id=pa.aprendiz_id
            JOIN fichas f ON f.id=a.ficha_id
            JOIN competencias c ON c.id=pa.competencia_id
            JOIN instructores i ON i.id=pa.instructor_id
            ORDER BY pa.created_at DESC
        ")->fetchAll(PDO::FETCH_NUM);
        $rows = $data;
        break;

    case 'acciones':
        $filename = 'acciones_remediales_' . date('Ymd_His') . '.csv';
        $headers  = ['Fecha','Aprendiz','Documento','Ficha','Competencia','Instructor','Tipo_Accion','Resultado','Novedad_Aprobacion','Descripcion'];
        $data = $db->query("
            SELECT ar.fecha_accion,
                   CONCAT(a.nombres,' ',a.apellidos), a.documento, f.numero_ficha,
                   c.nombre, CONCAT(i.nombres,' ',i.apellidos),
                   ar.tipo_accion, ar.resultado,
                   IF(ar.novedad_aprobacion=1,'Sí','No'),
                   ar.descripcion
            FROM acciones_remediales ar
            JOIN pendientes_aprendices pa ON pa.id=ar.pendiente_id
            JOIN aprendices a ON a.id=pa.aprendiz_id
            JOIN fichas f ON f.id=a.ficha_id
            JOIN competencias c ON c.id=pa.competencia_id
            JOIN instructores i ON i.id=ar.instructor_id
            ORDER BY ar.fecha_accion DESC
        ")->fetchAll(PDO::FETCH_NUM);
        $rows = $data;
        break;

    case 'instructores':
        $filename = 'instructores_' . date('Ymd_His') . '.csv';
        $headers  = ['Documento','Nombres','Apellidos','Email','Telefono','Tipo','Activo'];
        $data = $db->query("
            SELECT documento, nombres, apellidos, email, telefono, tipo,
                   IF(activo=1,'Sí','No')
            FROM instructores ORDER BY apellidos, nombres
        ")->fetchAll(PDO::FETCH_NUM);
        $rows = $data;
        break;

    case 'competencias':
        $filename = 'competencias_' . date('Ymd_His') . '.csv';
        $headers  = ['Codigo','Nombre','Programa','Trimestre','Horas','Total_Resultados','Activa'];
        $data = $db->query("
            SELECT c.codigo, c.nombre, p.nombre AS programa, c.trimestre, c.horas,
                   COUNT(ra.id) AS total_resultados,
                   IF(c.activa=1,'Sí','No')
            FROM competencias c
            JOIN programas p ON p.id=c.programa_id
            LEFT JOIN resultados_aprendizaje ra ON ra.competencia_id=c.id
            GROUP BY c.id
            ORDER BY p.nombre, c.trimestre, c.nombre
        ")->fetchAll(PDO::FETCH_NUM);
        $rows = $data;
        break;

    case 'resultados_aprendizaje':
        $filename = 'resultados_aprendizaje_' . date('Ymd_His') . '.csv';
        $headers  = ['Codigo_Resultado','Nombre_Resultado','Competencia','Programa','Activo'];
        $data = $db->query("
            SELECT ra.codigo, ra.nombre, c.nombre AS competencia, p.nombre AS programa,
                   IF(ra.activo=1,'Sí','No')
            FROM resultados_aprendizaje ra
            JOIN competencias c ON c.id=ra.competencia_id
            JOIN programas p ON p.id=c.programa_id
            ORDER BY p.nombre, c.nombre, ra.nombre
        ")->fetchAll(PDO::FETCH_NUM);
        $rows = $data;
        break;

    case 'comite':
        $filename = 'comite_' . date('Ymd_His') . '.csv';
        $headers  = ['Fecha_Remision','Aprendiz','Documento','Ficha','Decision','Motivo','Observaciones'];
        $data = $db->query("
            SELECT ca.fecha_remision,
                   CONCAT(a.nombres,' ',a.apellidos), a.documento, f.numero_ficha,
                   ca.decision, ca.motivo_remision, ca.observaciones_comite
            FROM comite_aprendices ca
            JOIN aprendices a ON a.id=ca.aprendiz_id
            JOIN fichas f ON f.id=a.ficha_id
            ORDER BY ca.fecha_remision DESC
        ")->fetchAll(PDO::FETCH_NUM);
        $rows = $data;
        break;

    case 'plantilla_aprendices':
        $filename = 'PLANTILLA_aprendices.csv';
        $headers  = ['documento','tipo_documento','nombres','apellidos','email','telefono','ficha','estado'];
        $rows     = [['12345678','CC','Juan','García','juan@email.com','3001234567','2354201','Activo']];
        break;

    case 'plantilla_instructores':
        $filename = 'PLANTILLA_instructores.csv';
        $headers  = ['documento','nombres','apellidos','email','telefono','tipo'];
        $rows     = [['87654321','María','López','maria@sena.edu.co','3109876543','Planta']];
        break;

    default:
        http_response_code(400); echo 'Tipo no válido'; exit;
}

// Enviar como archivo descargable
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$out = fopen('php://output', 'w');
// BOM para Excel en Windows
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, $headers, ';');
foreach ($rows as $row) {
    fputcsv($out, $row, ';');
}
fclose($out);
exit;
