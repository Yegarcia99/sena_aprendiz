<?php
// Vista imprimible del expediente. El navegador permite guardarla como PDF.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
requireLogin();
denyIfAprendiz();

$db = getDB();
ensureExpedienteSchema($db);

$aprendizId = (int)($_GET['aprendiz_id'] ?? 0);
if (!$aprendizId) {
    die('Seleccione un aprendiz para generar el expediente.');
}

$s = $db->prepare("
    SELECT a.*, CONCAT(a.nombres,' ',a.apellidos) AS nombre_completo,
           f.numero_ficha, f.jornada, p.nombre AS programa
    FROM aprendices a
    JOIN fichas f ON f.id=a.ficha_id
    JOIN programas p ON p.id=f.programa_id
    WHERE a.id=?
");
$s->execute([$aprendizId]);
$aprendiz = $s->fetch();
if (!$aprendiz) {
    die('Aprendiz no encontrado.');
}

$p = $db->prepare("
    SELECT pa.*, c.nombre AS competencia, ra.nombre AS resultado,
           CONCAT(i.nombres,' ',i.apellidos) AS instructor
    FROM pendientes_aprendices pa
    JOIN competencias c ON c.id=pa.competencia_id
    LEFT JOIN resultados_aprendizaje ra ON ra.id=pa.resultado_id
    JOIN instructores i ON i.id=pa.instructor_id
    WHERE pa.aprendiz_id=?
    ORDER BY pa.created_at DESC
");
$p->execute([$aprendizId]);
$pendientes = $p->fetchAll();

$a = $db->prepare("
    SELECT ar.*, c.nombre AS competencia,
           CONCAT(i.nombres,' ',i.apellidos) AS instructor
    FROM acciones_remediales ar
    JOIN pendientes_aprendices pa ON pa.id=ar.pendiente_id
    JOIN competencias c ON c.id=pa.competencia_id
    JOIN instructores i ON i.id=ar.instructor_id
    WHERE pa.aprendiz_id=?
    ORDER BY ar.fecha_accion DESC, ar.created_at DESC
");
$a->execute([$aprendizId]);
$acciones = $a->fetchAll();

$ev = $db->prepare("
    SELECT afe.*, c.nombre AS competencia, ar.tipo_accion,
           ar.estado_entrega, ar.estado_revision, ar.observacion_revision,
           CONCAT(u.nombres,' ',u.apellidos) AS creado_por_nombre,
           (
               SELECT se.archivo_nombre
               FROM soportes_expediente se
               WHERE se.accion_id = afe.accion_id
                 AND se.tipo_soporte = 'Evidencia entregada por aprendiz'
               ORDER BY se.created_at DESC
               LIMIT 1
           ) AS evidencia_nombre
    FROM academico_flujo_eventos afe
    JOIN pendientes_aprendices pa ON pa.id = afe.pendiente_id
    JOIN competencias c ON c.id = pa.competencia_id
    LEFT JOIN acciones_remediales ar ON ar.id = afe.accion_id
    LEFT JOIN usuarios u ON u.id = afe.creado_por
    WHERE afe.aprendiz_id=?
    ORDER BY afe.created_at DESC, afe.id DESC
");
$ev->execute([$aprendizId]);
$eventosAcademicos = $ev->fetchAll();

$pl = $db->prepare("
    SELECT pm.*, c.nombre AS competencia,
           CONCAT(i.nombres,' ',i.apellidos) AS instructor,
           CONCAT(u.nombres,' ',u.apellidos) AS coordinador
    FROM planes_mejoramiento pm
    JOIN pendientes_aprendices pa ON pa.id=pm.pendiente_id
    JOIN competencias c ON c.id=pa.competencia_id
    LEFT JOIN instructores i ON i.id=pm.instructor_id
    LEFT JOIN usuarios u ON u.id=pm.coordinador_id
    WHERE pm.aprendiz_id=?
    ORDER BY pm.fecha_concertacion DESC, pm.id DESC
");
$pl->execute([$aprendizId]);
$planes = $pl->fetchAll();

$so = $db->prepare("SELECT * FROM soportes_expediente WHERE aprendiz_id=? ORDER BY created_at DESC");
$so->execute([$aprendizId]);
$soportes = $so->fetchAll();

$no = $db->prepare("SELECT * FROM notificaciones WHERE aprendiz_id=? ORDER BY fecha_envio DESC");
$no->execute([$aprendizId]);
$notificaciones = $no->fetchAll();

$au = $db->prepare("
    SELECT *
    FROM auditoria_cambios
    WHERE aprendiz_id=?
    ORDER BY created_at DESC, id DESC
    LIMIT 80
");
$au->execute([$aprendizId]);
$auditoriaCambios = $au->fetchAll();

$diagnostico = diagnosticoExpediente($db, $aprendizId);

function pdfText($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function pdfFecha($value, string $format = 'd/m/Y'): string {
    return $value ? date($format, strtotime($value)) : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Expediente <?= pdfText($aprendiz['documento']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; color:#17231b; margin:0; background:#eef4f0; font-size:12px; }
        .page { width: 216mm; min-height: 279mm; margin: 14px auto; background:#fff; padding:18mm; box-shadow:0 8px 28px rgba(0,0,0,.12); }
        .toolbar { width:216mm; margin:14px auto 0; display:flex; justify-content:flex-end; gap:8px; }
        .btn { border:0; border-radius:6px; padding:9px 14px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-primary { background:#16833a; color:#fff; }
        .btn-secondary { background:#e9eee9; color:#1f3328; }
        .header { display:flex; justify-content:space-between; gap:18px; border-bottom:3px solid #16833a; padding-bottom:12px; margin-bottom:16px; }
        .brand { font-size:22px; font-weight:800; color:#16833a; letter-spacing:1px; }
        .subtitle { font-size:12px; color:#58705f; margin-top:3px; }
        .meta { text-align:right; font-size:11px; color:#4f6458; line-height:1.5; }
        h1 { font-size:18px; margin:0 0 5px; }
        h2 { font-size:14px; margin:18px 0 8px; color:#16833a; border-bottom:1px solid #d7e3dc; padding-bottom:5px; }
        .summary { display:grid; grid-template-columns: repeat(4, 1fr); gap:8px; margin:12px 0 16px; }
        .box { border:1px solid #d7e3dc; border-radius:6px; padding:9px; }
        .box strong { display:block; font-size:18px; color:#16833a; }
        .info { display:grid; grid-template-columns: 1fr 1fr; gap:8px 18px; margin-bottom:10px; }
        .label { color:#5e7468; font-size:10px; text-transform:uppercase; font-weight:700; }
        table { width:100%; border-collapse:collapse; margin-bottom:12px; page-break-inside:auto; }
        th, td { border:1px solid #d7e3dc; padding:6px; vertical-align:top; }
        th { background:#f1f6f3; color:#2d5e44; font-size:10px; text-transform:uppercase; text-align:left; }
        tr { page-break-inside:avoid; page-break-after:auto; }
        .muted { color:#65786d; font-size:11px; }
        .badge { display:inline-block; border-radius:999px; padding:2px 7px; background:#e8f3ff; color:#0b5c9a; font-size:10px; font-weight:700; }
        .empty { color:#7b8e82; font-style:italic; padding:8px 0; }
        .footer { margin-top:20px; border-top:1px solid #d7e3dc; padding-top:8px; color:#667a70; font-size:10px; display:flex; justify-content:space-between; }
        @media print {
            body { background:#fff; }
            .toolbar { display:none; }
            .page { margin:0; width:auto; min-height:auto; box-shadow:none; padding:12mm; }
            a { color:#17231b; text-decoration:none; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <a class="btn btn-secondary" href="expediente.php?aprendiz_id=<?= (int)$aprendizId ?>">Volver</a>
    <button class="btn btn-primary" onclick="window.print()">Imprimir / Guardar PDF</button>
</div>

<main class="page">
    <section class="header">
        <div>
            <div class="brand">SENA</div>
            <div class="subtitle">Expediente academico del aprendiz</div>
        </div>
        <div class="meta">
            Generado: <?= date('d/m/Y H:i') ?><br>
            Usuario: <?= pdfText((getCurrentUser()['nombres'] ?? '') . ' ' . (getCurrentUser()['apellidos'] ?? '')) ?>
        </div>
    </section>

    <h1><?= pdfText($aprendiz['nombre_completo']) ?></h1>
    <div class="info">
        <div><div class="label">Documento</div><?= pdfText($aprendiz['documento']) ?></div>
        <div><div class="label">Correo</div><?= pdfText($aprendiz['email'] ?? '') ?></div>
        <div><div class="label">Ficha</div><?= pdfText($aprendiz['numero_ficha']) ?></div>
        <div><div class="label">Programa</div><?= pdfText($aprendiz['programa']) ?></div>
        <div><div class="label">Jornada</div><?= pdfText($aprendiz['jornada'] ?? '') ?></div>
        <div><div class="label">Estado</div><?= pdfText($aprendiz['estado'] ?? '') ?></div>
    </div>

    <div class="summary">
        <div class="box"><strong><?= (int)$diagnostico['pendientes'] ?></strong>Pendientes</div>
        <div class="box"><strong><?= (int)$diagnostico['acciones'] ?></strong>Acciones</div>
        <div class="box"><strong><?= (int)$diagnostico['planes'] ?></strong>Planes</div>
        <div class="box"><strong><?= (int)$diagnostico['soportes'] ?></strong>Soportes</div>
    </div>

    <h2>Trazabilidad Academica</h2>
    <?php if (empty($eventosAcademicos)): ?>
        <div class="empty">No hay trazabilidad academica registrada.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>Fecha</th><th>Evento</th><th>Competencia</th><th>Estado</th><th>Detalle</th><th>Evidencia</th></tr></thead>
        <tbody>
        <?php foreach ($eventosAcademicos as $evAcad): ?>
            <tr>
                <td><?= pdfFecha($evAcad['created_at'], 'd/m/Y H:i') ?></td>
                <td><strong><?= pdfText($evAcad['tipo_evento']) ?></strong><br><span class="muted"><?= pdfText($evAcad['creado_por_nombre'] ?? '') ?></span></td>
                <td><?= pdfText($evAcad['competencia']) ?></td>
                <td><span class="badge"><?= pdfText($evAcad['estado_nuevo']) ?></span><br><span class="muted"><?= pdfText($evAcad['estado_revision'] ?? '') ?></span></td>
                <td>
                    <?php if (!empty($evAcad['tipo_accion'])): ?><strong><?= pdfText($evAcad['tipo_accion']) ?></strong><br><?php endif; ?>
                    <?= nl2br(pdfText($evAcad['descripcion'] ?? '')) ?>
                    <?php if (!empty($evAcad['observacion_revision'])): ?><br><strong>Revision:</strong> <?= nl2br(pdfText($evAcad['observacion_revision'])) ?><?php endif; ?>
                    <?php if (!empty($evAcad['fecha_limite'])): ?><br><strong>Limite:</strong> <?= pdfFecha($evAcad['fecha_limite']) ?><?php endif; ?>
                </td>
                <td><?= pdfText($evAcad['evidencia_nombre'] ?? 'Sin archivo') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Historial de Cambios</h2>
    <?php if (empty($auditoriaCambios)): ?>
        <div class="empty">No hay cambios auditados para este aprendiz.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Modulo</th><th>Accion</th><th>Cambio</th></tr></thead>
        <tbody>
        <?php foreach ($auditoriaCambios as $aud): ?>
            <tr>
                <td><?= pdfFecha($aud['created_at'], 'd/m/Y H:i') ?></td>
                <td><?= pdfText($aud['usuario_nombre'] ?: 'Sistema') ?><br><span class="muted"><?= pdfText($aud['usuario_rol'] ?? '') ?></span></td>
                <td><?= pdfText($aud['modulo']) ?></td>
                <td><?= pdfText($aud['accion']) ?></td>
                <td>
                    <?= nl2br(pdfText($aud['descripcion'] ?? '')) ?>
                    <?php if ($aud['valor_anterior'] !== null || $aud['valor_nuevo'] !== null): ?>
                        <br><strong>Antes:</strong> <?= pdfText($aud['valor_anterior'] ?? '-') ?>
                        <br><strong>Despues:</strong> <?= pdfText($aud['valor_nuevo'] ?? '-') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Pendientes</h2>
    <?php if (empty($pendientes)): ?>
        <div class="empty">No hay pendientes registrados.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>Fecha</th><th>Competencia</th><th>Resultado</th><th>Instructor</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($pendientes as $pItem): ?>
            <tr>
                <td><?= pdfFecha($pItem['fecha_registro']) ?></td>
                <td><?= pdfText($pItem['competencia']) ?></td>
                <td><?= pdfText($pItem['resultado'] ?? '') ?></td>
                <td><?= pdfText($pItem['instructor']) ?></td>
                <td><?= pdfText($pItem['estado']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Acciones Remediales</h2>
    <?php if (empty($acciones)): ?>
        <div class="empty">No hay acciones registradas.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Competencia</th><th>Instructor</th><th>Resultado</th><th>Observaciones</th></tr></thead>
        <tbody>
        <?php foreach ($acciones as $aItem): ?>
            <tr>
                <td><?= pdfFecha($aItem['fecha_accion']) ?></td>
                <td><?= pdfText($aItem['tipo_accion']) ?></td>
                <td><?= pdfText($aItem['competencia']) ?></td>
                <td><?= pdfText($aItem['instructor']) ?></td>
                <td><?= pdfText($aItem['resultado']) ?></td>
                <td><?= nl2br(pdfText($aItem['observaciones'] ?? $aItem['observacion_revision'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Planes Registrados</h2>
    <?php if (empty($planes)): ?>
        <div class="empty">No hay planes registrados.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>Fecha</th><th>Instancia</th><th>Competencia</th><th>Instructor</th><th>Coordinador</th><th>Estado</th><th>Plan</th></tr></thead>
        <tbody>
        <?php foreach ($planes as $plItem): ?>
            <tr>
                <td><?= pdfFecha($plItem['fecha_concertacion']) ?></td>
                <td><?= pdfText($plItem['instancia']) ?></td>
                <td><?= pdfText($plItem['competencia']) ?></td>
                <td><?= pdfText($plItem['instructor'] ?? '') ?></td>
                <td><?= pdfText($plItem['coordinador'] ?? '') ?></td>
                <td><?= pdfText($plItem['estado']) ?></td>
                <td><?= nl2br(pdfText($plItem['descripcion_plan'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Soportes</h2>
    <?php if (empty($soportes)): ?>
        <div class="empty">No hay soportes cargados.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Archivo</th><th>Descripcion</th></tr></thead>
        <tbody>
        <?php foreach ($soportes as $sop): ?>
            <tr>
                <td><?= pdfFecha($sop['created_at'], 'd/m/Y H:i') ?></td>
                <td><?= pdfText($sop['tipo_soporte']) ?></td>
                <td><?= pdfText($sop['archivo_nombre']) ?></td>
                <td><?= nl2br(pdfText($sop['descripcion'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Notificaciones</h2>
    <?php if (empty($notificaciones)): ?>
        <div class="empty">No hay notificaciones registradas.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>Fecha</th><th>Destino</th><th>Asunto</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($notificaciones as $nItem): ?>
            <tr>
                <td><?= pdfFecha($nItem['fecha_envio'], 'd/m/Y H:i') ?></td>
                <td><?= pdfText($nItem['correo_destino']) ?></td>
                <td><?= pdfText($nItem['asunto']) ?></td>
                <td><?= pdfText($nItem['estado_envio']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="footer">
        <span>SENA - Seguimiento de Aprendices</span>
        <span>Expediente generado desde el sistema</span>
    </div>
</main>
</body>
</html>
