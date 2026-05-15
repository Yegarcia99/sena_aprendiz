<?php
// pages/expediente.php - Expediente integral del aprendiz
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
requireLogin();

$pageTitle = 'Expediente del Aprendiz';
$db = getDB();
ensureExpedienteSchema($db);

$msg = $err = '';
$user = getCurrentUser();
$aprendizId = (int)($_GET['aprendiz_id'] ?? $_POST['aprendiz_id'] ?? 0);
$pendienteId = (int)($_GET['pendiente_id'] ?? $_POST['pendiente_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $form = $_POST['form'] ?? '';
        if ($form === 'plan') {
            $data = [
                'pendiente_id' => (int)($_POST['pendiente_id'] ?? 0),
                'aprendiz_id' => (int)($_POST['aprendiz_id'] ?? 0),
                'instancia' => $_POST['instancia'] ?? 'Primera instancia',
                'fecha_concertacion' => $_POST['fecha_concertacion'] ?? date('Y-m-d'),
                'evidencia_conocimiento' => isset($_POST['evidencia_conocimiento']) ? 1 : 0,
                'evidencia_producto' => isset($_POST['evidencia_producto']) ? 1 : 0,
                'evidencia_desempeno' => isset($_POST['evidencia_desempeno']) ? 1 : 0,
                'descripcion_plan' => trim($_POST['descripcion_plan'] ?? ''),
                'compromisos' => trim($_POST['compromisos'] ?? ''),
                'estado' => $_POST['estado'] ?? 'Abierto',
                'instructor_id' => (int)($_POST['instructor_id'] ?? 0) ?: null,
                'coordinador_id' => (int)($_POST['coordinador_id'] ?? 0) ?: null,
                'firma_instructor' => $_POST['firma_instructor'] ?? null,
                'firma_coordinador' => $_POST['firma_coordinador'] ?? null,
                'firma_aprendiz' => $_POST['firma_aprendiz'] ?? null,
            ];
            if (!$data['pendiente_id'] || !$data['aprendiz_id'] || !$data['descripcion_plan']) {
                throw new RuntimeException('Complete pendiente, aprendiz y descripcion del plan.');
            }
            $stmt = $db->prepare("
                INSERT INTO planes_mejoramiento
                (pendiente_id, aprendiz_id, instancia, fecha_concertacion, evidencia_conocimiento, evidencia_producto, evidencia_desempeno, descripcion_plan, compromisos, estado, instructor_id, coordinador_id, firma_instructor, firma_coordinador, firma_aprendiz)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute(array_values($data));
            $planId = (int)$db->lastInsertId();
            if (!empty($_FILES['soporte_plan'])) {
                guardarSoporteExpediente($db, $_FILES['soporte_plan'], [
                    'aprendiz_id' => $data['aprendiz_id'],
                    'pendiente_id' => $data['pendiente_id'],
                    'plan_id' => $planId,
                    'tipo_soporte' => 'Acta de plan de mejoramiento',
                    'descripcion' => 'Soporte cargado con el plan de mejoramiento',
                    'subido_por' => $user['id'] ?? null,
                ]);
            }
            $db->prepare("UPDATE pendientes_aprendices SET estado=? WHERE id=?")
               ->execute([$data['instancia'], $data['pendiente_id']]);
            $msg = 'Plan de mejoramiento registrado.';
        }

        if ($form === 'soporte') {
            $aprendizId = (int)($_POST['aprendiz_id'] ?? 0);
            if (!$aprendizId || empty($_FILES['archivo_soporte'])) {
                throw new RuntimeException('Seleccione aprendiz y archivo de soporte.');
            }
            guardarSoporteExpediente($db, $_FILES['archivo_soporte'], [
                'aprendiz_id' => $aprendizId,
                'pendiente_id' => (int)($_POST['pendiente_id'] ?? 0) ?: null,
                'accion_id' => (int)($_POST['accion_id'] ?? 0) ?: null,
                'plan_id' => (int)($_POST['plan_id'] ?? 0) ?: null,
                'tipo_soporte' => $_POST['tipo_soporte'] ?? 'Soporte general',
                'descripcion' => trim($_POST['descripcion_soporte'] ?? ''),
                'subido_por' => $user['id'] ?? null,
            ]);
            $msg = 'Soporte agregado al expediente.';
        }

        if ($form === 'notificacion') {
            $stmt = $db->prepare("
                INSERT INTO notificaciones
                (aprendiz_id, pendiente_id, referencia_tipo, referencia_id, correo_destino, asunto, mensaje, estado_envio, enviado_por)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                (int)$_POST['aprendiz_id'],
                (int)($_POST['pendiente_id'] ?? 0) ?: null,
                $_POST['referencia_tipo'] ?? 'Expediente',
                (int)($_POST['referencia_id'] ?? 0) ?: null,
                trim($_POST['correo_destino'] ?? ''),
                trim($_POST['asunto'] ?? ''),
                trim($_POST['mensaje'] ?? ''),
                'Registrada',
                $user['id'] ?? null,
            ]);
            $msg = 'Notificacion registrada como evidencia.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$aprendices = $db->query("
    SELECT a.id, CONCAT(a.apellidos, ', ', a.nombres) AS nombre, a.documento, a.email, f.numero_ficha
    FROM aprendices a
    JOIN fichas f ON f.id=a.ficha_id
    WHERE a.estado='Activo'
    ORDER BY a.apellidos, a.nombres
")->fetchAll();

$aprendiz = null;
$pendientes = $acciones = $planes = $soportes = $notificaciones = [];
$diagnostico = null;
$timeline = [];

if ($aprendizId) {
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
        SELECT ar.*, pa.competencia_id, c.nombre AS competencia,
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

    $diagnostico = diagnosticoExpediente($db, $aprendizId);

    foreach ($pendientes as $pItem) {
        $timeline[] = [
            'fecha' => $pItem['fecha_registro'],
            'tipo' => 'Pendiente',
            'titulo' => $pItem['competencia'],
            'detalle' => $pItem['estado'] . ' | ' . ($pItem['tipo_caso'] ?? 'Academico'),
        ];
    }
    foreach ($acciones as $aItem) {
        $timeline[] = [
            'fecha' => $aItem['fecha_accion'],
            'tipo' => 'Accion',
            'titulo' => $aItem['tipo_accion'],
            'detalle' => $aItem['resultado'] . ' | ' . $aItem['competencia'],
        ];
    }
    foreach ($planes as $plItem) {
        $timeline[] = [
            'fecha' => $plItem['fecha_concertacion'],
            'tipo' => 'Plan',
            'titulo' => $plItem['instancia'],
            'detalle' => $plItem['estado'] . ' | ' . $plItem['competencia'],
        ];
    }
    foreach ($notificaciones as $nItem) {
        $timeline[] = [
            'fecha' => substr($nItem['fecha_envio'], 0, 10),
            'tipo' => 'Notificacion',
            'titulo' => $nItem['asunto'],
            'detalle' => $nItem['estado_envio'] . ' | ' . $nItem['correo_destino'],
        ];
    }
    usort($timeline, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
}

$instructores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM instructores WHERE activo=1 ORDER BY nombres")->fetchAll();
$coordinadores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM usuarios WHERE rol IN ('Administrador','Coordinador') AND activo=1 ORDER BY nombres")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Expediente del Aprendiz</div>
        <div class="page-subtitle">Trazabilidad de acciones, planes, soportes y notificaciones antes de comite</div>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
    <select name="aprendiz_id" class="search-input" style="min-width:320px" required>
        <option value="">-- Seleccionar aprendiz --</option>
        <?php foreach ($aprendices as $ap): ?>
        <option value="<?= $ap['id'] ?>" <?= $aprendizId===$ap['id']?'selected':'' ?>>
            <?= sanitize($ap['nombre']) ?> - <?= sanitize($ap['documento']) ?> / Ficha <?= sanitize($ap['numero_ficha']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Abrir expediente</button>
</form>

<?php if ($aprendiz): ?>
<div class="stats-grid" style="margin-bottom:18px">
    <div class="stat-card"><div class="stat-value"><?= (int)$diagnostico['pendientes'] ?></div><div class="stat-label">Pendientes activos</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int)$diagnostico['acciones'] ?></div><div class="stat-label">Acciones / justificaciones</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int)$diagnostico['planes'] ?></div><div class="stat-label">Planes de mejora</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int)$diagnostico['soportes'] ?></div><div class="stat-label">Soportes cargados</div></div>
</div>

<div class="table-card" style="margin-bottom:18px">
    <div class="table-card-header">
        <div>
            <div class="table-card-title"><?= sanitize($aprendiz['nombre_completo']) ?></div>
            <div style="font-size:12px;color:var(--gris-text)">
                Documento <?= sanitize($aprendiz['documento']) ?> | Ficha <?= sanitize($aprendiz['numero_ficha']) ?> | <?= sanitize($aprendiz['programa']) ?>
            </div>
        </div>
        <a href="comite.php?aprendiz_id=<?= $aprendizId ?>" class="btn btn-secondary btn-sm">Preparar comite</a>
    </div>
    <?php if (!$diagnostico['completo']): ?>
    <div class="alert alert-warning" style="margin:14px">
        Expediente incompleto para comite: <?= sanitize(implode(' ', $diagnostico['faltantes'])) ?>
    </div>
    <?php else: ?>
    <div class="alert alert-success" style="margin:14px">El expediente tiene pendientes, acciones, plan y soportes registrados.</div>
    <?php endif; ?>
</div>

<div class="table-card" style="margin-bottom:18px">
    <div class="table-card-header">
        <div>
            <div class="table-card-title">Linea de tiempo del expediente</div>
            <div class="section-kicker">Secuencia resumida de pendientes, acciones, planes y notificaciones.</div>
        </div>
        <a href="asistente_caso.php" class="btn btn-primary btn-sm">Nuevo caso guiado</a>
    </div>
    <div class="timeline">
        <?php if (empty($timeline)): ?>
            <div class="empty-state"><p>Este aprendiz aun no tiene movimientos en el expediente.</p></div>
        <?php else: foreach (array_slice($timeline, 0, 12) as $item): ?>
            <div class="timeline-item">
                <strong><?= sanitize($item['tipo']) ?> - <?= sanitize($item['titulo']) ?></strong>
                <small><?= date('d/m/Y', strtotime($item['fecha'])) ?> | <?= sanitize($item['detalle']) ?></small>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="table-card" style="margin-bottom:18px">
    <div class="table-card-header"><div class="table-card-title">Pendientes</div></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Fecha</th><th>Competencia</th><th>Instructor</th><th>Estado</th><th>Opciones</th></tr></thead>
            <tbody>
            <?php foreach ($pendientes as $p): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($p['fecha_registro'])) ?></td>
                    <td><strong><?= sanitize($p['competencia']) ?></strong><br><small><?= sanitize($p['resultado'] ?? '') ?></small></td>
                    <td><?= sanitize($p['instructor']) ?></td>
                    <td><span class="badge badge-proceso"><?= sanitize($p['estado']) ?></span></td>
                    <td><a class="btn btn-sm btn-primary" href="acciones.php?action=new&pendiente_id=<?= $p['id'] ?>">Accion</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card" style="margin-bottom:18px">
    <div class="table-card-header"><div class="table-card-title">Registrar plan de mejoramiento</div></div>
    <form method="POST" enctype="multipart/form-data" style="padding:16px">
        <input type="hidden" name="form" value="plan">
        <input type="hidden" name="aprendiz_id" value="<?= $aprendizId ?>">
        <div class="form-grid">
            <div class="form-group">
                <label>Pendiente *</label>
                <select name="pendiente_id" required>
                    <?php foreach ($pendientes as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $pendienteId===$p['id']?'selected':'' ?>><?= sanitize($p['competencia']) ?> - <?= sanitize($p['estado']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Instancia *</label>
                <select name="instancia"><option>Primera instancia</option><option>Segunda instancia</option></select>
            </div>
            <div class="form-group">
                <label>Fecha concertacion *</label>
                <input type="date" name="fecha_concertacion" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Instructor</label>
                <select name="instructor_id"><option value="">-- Seleccionar --</option><?php foreach ($instructores as $i): ?><option value="<?= $i['id'] ?>"><?= sanitize($i['nombre']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group">
                <label>Coordinador</label>
                <select name="coordinador_id"><option value="">-- Seleccionar --</option><?php foreach ($coordinadores as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['nombre']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group">
                <label>Estado</label>
                <select name="estado"><option>Abierto</option><option>Cumplido</option><option>No cumplido</option><option>Cerrado</option></select>
            </div>
            <div class="form-group full" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap">
                <label style="text-transform:none"><input type="checkbox" name="evidencia_conocimiento" style="width:auto"> Evidencia de conocimiento</label>
                <label style="text-transform:none"><input type="checkbox" name="evidencia_producto" style="width:auto"> Evidencia de producto</label>
                <label style="text-transform:none"><input type="checkbox" name="evidencia_desempeno" style="width:auto"> Evidencia de desempeno</label>
            </div>
            <div class="form-group full"><label>Plan concertado *</label><textarea name="descripcion_plan" required placeholder="Orientaciones, estrategias metodologicas y evidencia que debe presentar el aprendiz."></textarea></div>
            <div class="form-group full"><label>Compromisos</label><textarea name="compromisos" placeholder="Fechas, responsables y acuerdos de seguimiento."></textarea></div>
            <div class="form-group full"><label>Acta o soporte del plan</label><input type="file" name="soporte_plan" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-primary" type="submit">Guardar plan</button></div>
    </form>
</div>

<div class="table-card" style="margin-bottom:18px">
    <div class="table-card-header"><div class="table-card-title">Planes registrados</div></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Fecha</th><th>Instancia</th><th>Competencia</th><th>Evidencias</th><th>Estado</th><th>Plan</th></tr></thead>
            <tbody>
            <?php if (empty($planes)): ?><tr><td colspan="6"><div class="empty-state"><p>No hay planes registrados.</p></div></td></tr><?php endif; ?>
            <?php foreach ($planes as $pln): ?>
            <tr>
                <td><?= date('d/m/Y', strtotime($pln['fecha_concertacion'])) ?></td>
                <td><strong><?= sanitize($pln['instancia']) ?></strong></td>
                <td><?= sanitize($pln['competencia']) ?></td>
                <td style="font-size:12px">
                    <?= $pln['evidencia_conocimiento'] ? 'Conocimiento ' : '' ?>
                    <?= $pln['evidencia_producto'] ? 'Producto ' : '' ?>
                    <?= $pln['evidencia_desempeno'] ? 'Desempeno' : '' ?>
                </td>
                <td><span class="badge badge-proceso"><?= sanitize($pln['estado']) ?></span></td>
                <td style="font-size:12px;max-width:360px"><?= sanitize(substr($pln['descripcion_plan'], 0, 180)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card" style="margin-bottom:18px">
    <div class="table-card-header"><div class="table-card-title">Soportes del expediente</div></div>
    <form method="POST" enctype="multipart/form-data" style="padding:16px;border-bottom:1px solid var(--gris-border)">
        <input type="hidden" name="form" value="soporte">
        <input type="hidden" name="aprendiz_id" value="<?= $aprendizId ?>">
        <div class="form-grid">
            <div class="form-group"><label>Tipo</label><select name="tipo_soporte"><option>Acta</option><option>Control de inasistencia</option><option>Evidencia academica</option><option>Notificacion</option><option>Soporte disciplinario</option><option>Otro</option></select></div>
            <div class="form-group"><label>Pendiente relacionado</label><select name="pendiente_id"><option value="">General</option><?php foreach ($pendientes as $p): ?><option value="<?= $p['id'] ?>"><?= sanitize($p['competencia']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group full"><label>Archivo</label><input type="file" name="archivo_soporte" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"></div>
            <div class="form-group full"><label>Descripcion</label><textarea name="descripcion_soporte"></textarea></div>
        </div>
        <div style="display:flex;justify-content:flex-end"><button class="btn btn-primary" type="submit">Subir soporte</button></div>
    </form>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Archivo</th><th>Descripcion</th></tr></thead>
            <tbody>
            <?php if (empty($soportes)): ?><tr><td colspan="4"><div class="empty-state"><p>No hay soportes cargados.</p></div></td></tr><?php endif; ?>
            <?php foreach ($soportes as $sop): ?>
            <tr>
                <td><?= date('d/m/Y H:i', strtotime($sop['created_at'])) ?></td>
                <td><?= sanitize($sop['tipo_soporte']) ?></td>
                <td><a href="<?= BASE_URL . '/' . sanitize($sop['archivo_ruta']) ?>" target="_blank"><?= sanitize($sop['archivo_nombre']) ?></a></td>
                <td style="font-size:12px"><?= sanitize($sop['descripcion'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card" style="margin-bottom:18px">
    <div class="table-card-header"><div class="table-card-title">Notificaciones</div></div>
    <form method="POST" style="padding:16px;border-bottom:1px solid var(--gris-border)">
        <input type="hidden" name="form" value="notificacion">
        <input type="hidden" name="aprendiz_id" value="<?= $aprendizId ?>">
        <div class="form-grid">
            <div class="form-group"><label>Correo destino</label><input type="email" name="correo_destino" value="<?= sanitize($aprendiz['email'] ?? '') ?>" required></div>
            <div class="form-group"><label>Asunto</label><input type="text" name="asunto" required placeholder="Concertacion de accion o plan"></div>
            <div class="form-group full"><label>Mensaje</label><textarea name="mensaje" required placeholder="Detalle de la citacion, evidencia, fecha y hora."></textarea></div>
        </div>
        <div style="display:flex;justify-content:flex-end"><button class="btn btn-primary" type="submit">Registrar notificacion</button></div>
    </form>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Fecha</th><th>Destino</th><th>Asunto</th><th>Estado</th></tr></thead>
            <tbody>
            <?php if (empty($notificaciones)): ?><tr><td colspan="4"><div class="empty-state"><p>No hay notificaciones registradas.</p></div></td></tr><?php endif; ?>
            <?php foreach ($notificaciones as $n): ?>
            <tr>
                <td><?= date('d/m/Y H:i', strtotime($n['fecha_envio'])) ?></td>
                <td><?= sanitize($n['correo_destino']) ?></td>
                <td><?= sanitize($n['asunto']) ?></td>
                <td><span class="badge badge-proceso"><?= sanitize($n['estado_envio']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
