<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
require_once __DIR__ . '/../includes/disciplinario_schema.php';
requireLogin();
denyIfAprendiz();

$db = getDB();
ensureExpedienteSchema($db);
ensureDisciplinarioSchema($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("
    SELECT da.*,
           dh.fecha_hecho, dh.tipo_hecho, dh.gravedad, dh.descripcion AS hecho_descripcion,
           dh.lugar, dh.testigos, dh.estado, dh.estado_flujo,
           CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre,
           a.documento,
           f.numero_ficha,
           p.nombre AS programa,
           CONCAT(i.nombres,' ',i.apellidos) AS instructor_nombre,
           CONCAT(u.nombres,' ',u.apellidos) AS gestor_nombre
    FROM disc_atenciones da
    JOIN disc_hechos dh ON dh.id=da.hecho_id
    JOIN aprendices a ON a.id=da.aprendiz_id
    JOIN fichas f ON f.id=a.ficha_id
    JOIN programas p ON p.id=f.programa_id
    LEFT JOIN instructores i ON i.id=da.instructor_id
    LEFT JOIN usuarios u ON u.id=da.gestor_id
    WHERE da.id=?
");
$stmt->execute([$id]);
$acta = $stmt->fetch();
if (!$acta) {
    http_response_code(404);
    echo 'Acta no encontrada';
    exit;
}

$ev = $db->prepare("SELECT * FROM disc_evidencias WHERE hecho_id=? ORDER BY created_at DESC");
$ev->execute([(int)$acta['hecho_id']]);
$evidencias = $ev->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acta disciplinaria</title>
    <style>
        body { font-family: Arial, sans-serif; color:#1b2a22; margin:0; background:#eef5f0; }
        .page { max-width:900px; margin:28px auto; background:#fff; padding:34px; border:1px solid #dce8df; }
        .top { display:flex; justify-content:space-between; gap:20px; border-bottom:3px solid #39a900; padding-bottom:16px; margin-bottom:20px; }
        h1 { font-size:22px; margin:0 0 6px; }
        h2 { font-size:15px; border-bottom:1px solid #dce8df; padding-bottom:7px; margin:24px 0 12px; }
        .muted { color:#5d7568; font-size:12px; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 18px; }
        .field { font-size:13px; }
        .field b { display:block; color:#577363; font-size:10px; text-transform:uppercase; letter-spacing:.6px; margin-bottom:3px; }
        .box { border:1px solid #dce8df; border-radius:6px; padding:12px; font-size:13px; line-height:1.55; min-height:42px; }
        .signs { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-top:28px; }
        .sign { border-top:1px solid #1b2a22; padding-top:7px; text-align:center; font-size:12px; min-height:80px; }
        .sign img { max-width:100%; height:54px; object-fit:contain; display:block; margin:0 auto 6px; }
        .actions { max-width:900px; margin:18px auto 0; display:flex; justify-content:flex-end; gap:8px; }
        .btn { background:#27824f; color:#fff; border:0; border-radius:6px; padding:10px 16px; font-weight:700; cursor:pointer; text-decoration:none; font-size:13px; }
        @media print {
            body { background:#fff; }
            .page { margin:0; max-width:none; border:0; padding:22px; }
            .actions { display:none; }
        }
    </style>
</head>
<body>
<div class="actions">
    <button class="btn" onclick="window.print()">Imprimir / guardar PDF</button>
</div>
<main class="page">
    <div class="top">
        <div>
            <h1>Acta de seguimiento disciplinario</h1>
            <div class="muted">Sistema de Seguimiento de Aprendices SENA</div>
        </div>
        <div class="muted" style="text-align:right">
            Acta No. <?= (int)$acta['id'] ?><br>
            Generada: <?= date('d/m/Y H:i') ?>
        </div>
    </div>

    <h2>Datos del aprendiz</h2>
    <div class="grid">
        <div class="field"><b>Aprendiz</b><?= sanitize($acta['aprendiz_nombre']) ?></div>
        <div class="field"><b>Documento</b><?= sanitize($acta['documento']) ?></div>
        <div class="field"><b>Ficha</b><?= sanitize($acta['numero_ficha']) ?></div>
        <div class="field"><b>Programa</b><?= sanitize($acta['programa']) ?></div>
    </div>

    <h2>Hecho disciplinario</h2>
    <div class="grid">
        <div class="field"><b>Fecha del hecho</b><?= date('d/m/Y', strtotime($acta['fecha_hecho'])) ?></div>
        <div class="field"><b>Tipo / gravedad</b><?= sanitize($acta['tipo_hecho']) ?> - <?= sanitize($acta['gravedad']) ?></div>
        <div class="field"><b>Lugar</b><?= sanitize($acta['lugar'] ?: 'No registrado') ?></div>
        <div class="field"><b>Testigos</b><?= sanitize($acta['testigos'] ?: 'No registrados') ?></div>
        <div class="field"><b>Estado</b><?= sanitize($acta['estado']) ?> / <?= sanitize($acta['estado_flujo']) ?></div>
    </div>
    <div class="box" style="margin-top:12px"><?= nl2br(sanitize($acta['hecho_descripcion'])) ?></div>

    <h2>Atencion, descargos y compromisos</h2>
    <div class="grid">
        <div class="field"><b>Fecha citacion</b><?= date('d/m/Y', strtotime($acta['fecha_citacion'])) ?></div>
        <div class="field"><b>Tipo atencion</b><?= sanitize($acta['tipo_atencion']) ?></div>
        <div class="field"><b>Instructor</b><?= sanitize($acta['instructor_nombre'] ?: 'No asignado') ?></div>
        <div class="field"><b>Gestor</b><?= sanitize($acta['gestor_nombre'] ?: 'No asignado') ?></div>
        <div class="field"><b>Seguimiento previsto</b><?= $acta['fecha_seguimiento'] ? date('d/m/Y', strtotime($acta['fecha_seguimiento'])) : 'No definido' ?></div>
        <div class="field"><b>Resultado</b><?= sanitize($acta['resultado']) ?></div>
    </div>
    <div class="box" style="margin-top:12px"><b>Descripcion:</b><br><?= nl2br(sanitize($acta['descripcion'])) ?></div>
    <div class="box" style="margin-top:10px"><b>Descargos del aprendiz:</b><br><?= nl2br(sanitize($acta['descargos_aprendiz'] ?: 'No registrados')) ?></div>
    <div class="box" style="margin-top:10px"><b>Compromisos:</b><br><?= nl2br(sanitize($acta['compromisos'] ?: 'No registrados')) ?></div>

    <h2>Soportes y evidencias</h2>
    <div class="box">
        <?php if (!empty($acta['archivo_nombre'])): ?>
            Soporte de atencion: <?= sanitize($acta['archivo_nombre']) ?><br>
        <?php endif; ?>
        <?php if (empty($evidencias)): ?>
            No hay evidencias entregadas por el aprendiz.
        <?php else: ?>
            <?php foreach ($evidencias as $e): ?>
                Evidencia aprendiz: <?= sanitize($e['archivo_nombre']) ?> - <?= date('d/m/Y H:i', strtotime($e['created_at'])) ?><br>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="signs">
        <div class="sign">
            <?php if ($acta['firma_instructor']): ?><img src="<?= htmlspecialchars($acta['firma_instructor']) ?>" alt="Firma instructor"><?php endif; ?>
            Instructor
        </div>
        <div class="sign">
            <?php if ($acta['firma_aprendiz']): ?><img src="<?= htmlspecialchars($acta['firma_aprendiz']) ?>" alt="Firma aprendiz"><?php endif; ?>
            Aprendiz
        </div>
        <div class="sign">
            <?php if ($acta['firma_gestor']): ?><img src="<?= htmlspecialchars($acta['firma_gestor']) ?>" alt="Firma gestor"><?php endif; ?>
            Gestor
        </div>
    </div>
</main>
</body>
</html>
