<?php
// pages/acciones.php - Acciones Remediales con firma digital
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
require_once __DIR__ . '/../includes/academico_flujo.php';
requireLogin();
denyIfAprendiz(); // Aprendiz no accede a acciones remediales
$pageTitle = 'Acciones Remediales';
$db  = getDB();
ensureExpedienteSchema($db);
$msg = $err = '';
$ff  = filtroFichas($db, 'a');
$action          = $_GET['action'] ?? 'list';
$id              = (int)($_GET['id'] ?? 0);
$pendienteFilter = (int)($_GET['pendiente_id'] ?? 0);

// Instructor puede asignar acciones remediales; edicion/eliminacion queda para gestor/coordinacion/admin.
$puedeCrear = !isAprendiz();
$puedeEditar = !isInstructor() && !isAprendiz();
$instructorActualId = instructorIdActual($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $accionRevision = $_POST['accion_revision'] ?? '';

    if ($accionRevision) {
        $accionIdRevision = (int)($_POST['accion_id'] ?? 0);
        $observacionRevision = trim($_POST['observacion_revision'] ?? '');
        $estadosPermitidos = [
            'aprobar' => 'Aprobada',
            'correccion' => 'Correccion solicitada',
            'rechazar' => 'No aprobada',
        ];

        if (!$accionIdRevision || !isset($estadosPermitidos[$accionRevision])) {
            $err = 'Seleccione una revision valida.';
        } else {
            $infoRevStmt = $db->prepare("
                SELECT ar.*, pa.aprendiz_id, pa.id AS pendiente_id
                FROM acciones_remediales ar
                JOIN pendientes_aprendices pa ON pa.id = ar.pendiente_id
                WHERE ar.id=?
            ");
            $infoRevStmt->execute([$accionIdRevision]);
            $infoRev = $infoRevStmt->fetch();

            if (!$infoRev) {
                $err = 'No se encontro la accion remedial.';
            } elseif (isInstructor() && (int)$infoRev['instructor_id'] !== $instructorActualId) {
                $err = 'Solo puede revisar acciones asignadas a usted.';
            } else {
                $estadoRevision = $estadosPermitidos[$accionRevision];
                $db->prepare("
                    UPDATE acciones_remediales
                    SET estado_revision=?,
                        fecha_revision=NOW(),
                        observacion_revision=?,
                        revisado_por=?,
                        estado_entrega=?
                    WHERE id=?
                ")->execute([
                    $estadoRevision,
                    $observacionRevision,
                    (int)(getCurrentUser()['id'] ?? 0) ?: null,
                    $accionRevision === 'correccion' ? 'Correccion solicitada' : $estadoRevision,
                    $accionIdRevision,
                ]);

                if ($accionRevision === 'aprobar') {
                    $db->prepare("UPDATE acciones_remediales SET resultado='Aprobado' WHERE id=?")->execute([$accionIdRevision]);
                    $db->prepare("UPDATE pendientes_aprendices SET estado='Superado', estado_flujo='Superado' WHERE id=?")->execute([(int)$infoRev['pendiente_id']]);
                } elseif ($accionRevision === 'rechazar') {
                    $db->prepare("UPDATE acciones_remediales SET resultado='No aprobado' WHERE id=?")->execute([$accionIdRevision]);
                    $db->prepare("UPDATE pendientes_aprendices SET estado='En proceso', estado_flujo='Evidencia no aprobada' WHERE id=?")->execute([(int)$infoRev['pendiente_id']]);
                } else {
                    $db->prepare("UPDATE pendientes_aprendices SET estado='En proceso', estado_flujo='Correccion solicitada' WHERE id=?")->execute([(int)$infoRev['pendiente_id']]);
                }

                registrarEventoAcademico($db, (int)$infoRev['pendiente_id'], (int)$infoRev['aprendiz_id'], 'Revision evidencia', $estadoRevision, $observacionRevision, null, 0, null, $accionIdRevision);
                notificarRevisionEvidencia($db, (int)$infoRev['pendiente_id'], $estadoRevision, $observacionRevision);
                $msg = 'Revision registrada y aprendiz notificado.';
            }
        }
        $action = 'list';
    } elseif (!$puedeCrear) {
        $err = 'No tiene permisos para registrar acciones remediales.';
    } else {
    $data = [
        'pendiente_id'       => (int)($_POST['pendiente_id'] ?? 0),
        'instructor_id'      => isInstructor() ? $instructorActualId : (int)($_POST['instructor_id'] ?? 0),
        'fecha_accion'       => $_POST['fecha_accion'] ?? date('Y-m-d'),
        'fecha_limite'       => $_POST['fecha_limite'] ?: null,
        'tipo_accion'        => $_POST['tipo_accion'] ?? '',
        'requiere_trabajo'   => isset($_POST['requiere_trabajo']) ? 1 : 0,
        'requiere_evidencia' => isset($_POST['requiere_evidencia']) ? 1 : 0,
        'requiere_sustentacion' => isset($_POST['requiere_sustentacion']) ? 1 : 0,
        'requiere_evaluacion' => isset($_POST['requiere_evaluacion']) ? 1 : 0,
        'requiere_tutoria'   => isset($_POST['requiere_tutoria']) ? 1 : 0,
        'otra_actividad'     => trim($_POST['otra_actividad'] ?? ''),
        'indicaciones_aprendiz' => trim($_POST['indicaciones_aprendiz'] ?? ''),
        'descripcion'        => trim($_POST['descripcion'] ?? ''),
        'resultado'          => $_POST['resultado'] ?? 'En proceso',
        'novedad_aprobacion' => isset($_POST['novedad_aprobacion']) ? 1 : 0,
        'observaciones'      => trim($_POST['observaciones'] ?? ''),
    ];

    // Firmas digitales — solo se guardan si la columna existe en la BD
    // Para agregarlas ejecuta en MySQL:
    // ALTER TABLE acciones_remediales ADD COLUMN firma_instructor MEDIUMTEXT, ADD COLUMN firma_aprendiz MEDIUMTEXT;
    $firmaInstructor = $_POST['firma_instructor'] ?? '';
    $firmaAprendiz   = $_POST['firma_aprendiz']   ?? '';

    if (!$data['pendiente_id'] || !$data['instructor_id'] || !$data['descripcion'] || !$data['tipo_accion']) {
        $err = 'Complete todos los campos obligatorios.';
    } else {
        $editId = (int)($_POST['edit_id'] ?? 0);

        // Detectar si las columnas de firma existen
        $tieneColumnasFirma = false;
        try {
            $db->query("SELECT firma_instructor FROM acciones_remediales LIMIT 1");
            $tieneColumnasFirma = true;
        } catch (PDOException $e) {
            $tieneColumnasFirma = false;
        }

        if ($editId && !$puedeEditar) {
            $err = 'El instructor solo puede registrar acciones nuevas.';
        } elseif ($editId) {
            if ($tieneColumnasFirma) {
                $stmt = $db->prepare("UPDATE acciones_remediales SET pendiente_id=?,instructor_id=?,fecha_accion=?,fecha_limite=?,tipo_accion=?,requiere_trabajo=?,requiere_evidencia=?,requiere_sustentacion=?,requiere_evaluacion=?,requiere_tutoria=?,otra_actividad=?,indicaciones_aprendiz=?,descripcion=?,resultado=?,novedad_aprobacion=?,observaciones=?,firma_instructor=?,firma_aprendiz=? WHERE id=?");
                $stmt->execute([...array_values($data), $firmaInstructor ?: null, $firmaAprendiz ?: null, $editId]);
            } else {
                $stmt = $db->prepare("UPDATE acciones_remediales SET pendiente_id=?,instructor_id=?,fecha_accion=?,fecha_limite=?,tipo_accion=?,requiere_trabajo=?,requiere_evidencia=?,requiere_sustentacion=?,requiere_evaluacion=?,requiere_tutoria=?,otra_actividad=?,indicaciones_aprendiz=?,descripcion=?,resultado=?,novedad_aprobacion=?,observaciones=? WHERE id=?");
                $stmt->execute([...array_values($data), $editId]);
            }
            $msg = 'Acción remedial actualizada.';
        } else {
            if ($tieneColumnasFirma) {
                $stmt = $db->prepare("INSERT INTO acciones_remediales (pendiente_id,instructor_id,fecha_accion,fecha_limite,tipo_accion,requiere_trabajo,requiere_evidencia,requiere_sustentacion,requiere_evaluacion,requiere_tutoria,otra_actividad,indicaciones_aprendiz,descripcion,resultado,novedad_aprobacion,observaciones,firma_instructor,firma_aprendiz) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([...array_values($data), $firmaInstructor ?: null, $firmaAprendiz ?: null]);
            } else {
                $stmt = $db->prepare("INSERT INTO acciones_remediales (pendiente_id,instructor_id,fecha_accion,fecha_limite,tipo_accion,requiere_trabajo,requiere_evidencia,requiere_sustentacion,requiere_evaluacion,requiere_tutoria,otra_actividad,indicaciones_aprendiz,descripcion,resultado,novedad_aprobacion,observaciones) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute(array_values($data));
            }
            $accionId = (int)$db->lastInsertId();
            if (!empty($_FILES['soporte_accion'])) {
                $aprendizStmt = $db->prepare("SELECT aprendiz_id FROM pendientes_aprendices WHERE id=?");
                $aprendizStmt->execute([$data['pendiente_id']]);
                $aprendizId = (int)$aprendizStmt->fetchColumn();
                guardarSoporteExpediente($db, $_FILES['soporte_accion'], [
                    'aprendiz_id' => $aprendizId,
                    'pendiente_id' => $data['pendiente_id'],
                    'accion_id' => $accionId,
                    'tipo_soporte' => $data['tipo_accion'] === 'Sin accion remedial - justificacion' ? 'Justificacion sin accion remedial' : 'Soporte de accion remedial',
                    'descripcion' => $data['observaciones'],
                    'subido_por' => getCurrentUser()['id'] ?? null,
                ]);
            }
            if ($data['resultado'] === 'Aprobado') {
                $db->prepare("UPDATE pendientes_aprendices SET estado='Superado', estado_flujo='Superado' WHERE id=?")->execute([$data['pendiente_id']]);
            } elseif ($data['resultado'] === 'No aprobado') {
                $db->prepare("UPDATE pendientes_aprendices SET estado='En proceso', estado_flujo='Accion remedial no aprobada', fecha_limite_actual=? WHERE id=?")->execute([$data['fecha_limite'], $data['pendiente_id']]);
            } else {
                $db->prepare("UPDATE pendientes_aprendices SET estado='En proceso', estado_flujo='Accion remedial asignada', fecha_limite_actual=? WHERE id=?")->execute([$data['fecha_limite'], $data['pendiente_id']]);
            }
            $aprendizStmt = $db->prepare("SELECT aprendiz_id FROM pendientes_aprendices WHERE id=?");
            $aprendizStmt->execute([$data['pendiente_id']]);
            $aprendizIdEvento = (int)$aprendizStmt->fetchColumn();
            registrarEventoAcademico($db, $data['pendiente_id'], $aprendizIdEvento, 'Accion remedial', 'Accion remedial asignada', $data['descripcion'], null, 0, $data['fecha_limite'], $accionId);
            notificarAccionRemedial($db, $data['pendiente_id'], $accionId, $data['fecha_limite']);
            $msg = 'Acción remedial registrada correctamente.';
        }
        $action = 'list';
    }
    } // end else puedeEscribir
}

if ($action === 'delete' && $id) {
    verifyCsrf();
    if (!$puedeEditar) {
        $err = 'No tiene permisos para eliminar acciones remediales.';
        $action = 'list';
    } else {
    try {
        $db->prepare("DELETE FROM acciones_remediales WHERE id=?")->execute([$id]);
        $msg = 'Acción remedial eliminada correctamente.';
    } catch (PDOException $e) {
        $err = 'Error al eliminar la acción: ' . $e->getMessage();
    }
    $action = 'list';
    }
}

$ffSql = !empty($ff['params']) ? ' AND ' . $ff['sql'] : '';
$pStmt = $db->prepare("SELECT pa.id, CONCAT(a.nombres,' ',a.apellidos) AS aprendiz, c.nombre AS competencia FROM pendientes_aprendices pa JOIN aprendices a ON a.id=pa.aprendiz_id JOIN competencias c ON c.id=pa.competencia_id WHERE pa.estado NOT IN ('Superado'){$ffSql} ORDER BY a.apellidos");
$pStmt->execute($ff['params']);
$pendientes = $pStmt->fetchAll();
$instructores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM instructores WHERE activo=1 ORDER BY nombres")->fetchAll();

$whereC = !empty($ff['params']) ? '(' . $ff['sql'] . ')' : '1=1';
$params = $ff['params'];
$search = trim($_GET['q'] ?? '');
if ($search) { $whereC .= " AND (CONCAT(a.nombres,' ',a.apellidos) LIKE ? OR c.nombre LIKE ? OR ar.tipo_accion LIKE ?)"; $params = array_merge($params, array_fill(0,3,"%$search%")); }
if ($pendienteFilter) { $whereC .= " AND ar.pendiente_id=?"; $params[] = $pendienteFilter; }

$page  = max(1,(int)($_GET['p']??1));
$limit = 20; $offset = ($page-1)*$limit;
$cnt = $db->prepare("SELECT COUNT(*) FROM acciones_remediales ar JOIN pendientes_aprendices pa ON pa.id=ar.pendiente_id JOIN aprendices a ON a.id=pa.aprendiz_id JOIN competencias c ON c.id=pa.competencia_id WHERE $whereC");
$cnt->execute($params); $total = $cnt->fetchColumn(); $pages = ceil($total/$limit);

$stmt = $db->prepare("
    SELECT ar.*, CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre, a.documento,
           c.nombre AS competencia_nombre, f.numero_ficha,
           CONCAT(i.nombres,' ',i.apellidos) AS instructor_nombre,
           (
               SELECT se.archivo_nombre
               FROM soportes_expediente se
               WHERE se.accion_id = ar.id
                 AND se.tipo_soporte = 'Evidencia entregada por aprendiz'
               ORDER BY se.created_at DESC
               LIMIT 1
           ) AS evidencia_nombre,
           (
               SELECT se.archivo_ruta
               FROM soportes_expediente se
               WHERE se.accion_id = ar.id
                 AND se.tipo_soporte = 'Evidencia entregada por aprendiz'
               ORDER BY se.created_at DESC
               LIMIT 1
           ) AS evidencia_ruta
    FROM acciones_remediales ar
    JOIN pendientes_aprendices pa ON pa.id=ar.pendiente_id
    JOIN aprendices a ON a.id=pa.aprendiz_id
    JOIN fichas f ON f.id=a.ficha_id
    JOIN competencias c ON c.id=pa.competencia_id
    JOIN instructores i ON i.id=ar.instructor_id
    WHERE $whereC ORDER BY ar.fecha_accion DESC, ar.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$acciones = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Acciones Remediales</div>
        <div class="page-subtitle">Registro de todas las intervenciones realizadas a aprendices con pendientes</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/ajax/exportar_excel.php?tipo=acciones" class="btn btn-excel">↓ Exportar Excel</a>
        <?php if ($puedeCrear): ?>
        <button class="btn btn-primary" onclick="openModal('modalAccion')">+ Nueva Acción</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div style="margin-bottom:18px">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="hidden" name="pendiente_id" value="<?=$pendienteFilter?>">
        <input type="text" name="q" class="search-input" placeholder="Buscar aprendiz, competencia..." value="<?= sanitize($search) ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
        <a href="acciones.php" class="btn btn-sm" style="background:#eee;color:#666">Limpiar</a>
    </form>
</div>

<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Historial de Acciones Remediales (<?= $total ?>)</div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Fecha</th><th>Aprendiz</th><th>Ficha</th><th>Competencia</th><th>Instructor</th><th>Tipo</th><th>Resultado</th><th>Entrega</th><th>Firmas</th><th>Opciones</th></tr></thead>
            <tbody>
            <?php if (empty($acciones)): ?>
                <tr><td colspan="9"><div class="empty-state"><div class="icon">◐</div><p>No hay acciones remediales registradas.</p></div></td></tr>
            <?php else: foreach ($acciones as $ac):
                $resCls = match($ac['resultado']) { 'Aprobado'=>'badge-superado','No aprobado'=>'badge-pendiente',default=>'badge-proceso' }; ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($ac['fecha_accion'])) ?></td>
                    <td><strong><?= sanitize($ac['aprendiz_nombre']) ?></strong><br><small style="color:#888"><?= sanitize($ac['documento']) ?></small></td>
                    <td><?= sanitize($ac['numero_ficha']) ?></td>
                    <td style="font-size:12px"><?= sanitize($ac['competencia_nombre']) ?></td>
                    <td style="font-size:12px"><?= sanitize($ac['instructor_nombre']) ?></td>
                    <td><span class="badge badge-proceso" style="font-size:10px"><?= sanitize($ac['tipo_accion']) ?></span></td>
                    <td><span class="badge <?= $resCls ?>"><?= sanitize($ac['resultado']) ?></span></td>
                    <td style="font-size:11px">
                        <?php if (($ac['estado_entrega'] ?? '') === 'Entregada'): ?>
                        <span class="badge badge-proceso">Entregada</span>
                        <?php elseif (($ac['estado_entrega'] ?? '') === 'Aprobada'): ?>
                        <span class="badge badge-superado">Aprobada</span>
                        <?php elseif (($ac['estado_entrega'] ?? '') === 'Correccion solicitada'): ?>
                        <span class="badge badge-pendiente">Correccion</span>
                        <?php elseif (($ac['estado_entrega'] ?? '') === 'No aprobada'): ?>
                        <span class="badge badge-pendiente">No aprobada</span>
                        <?php else: ?>
                        <span style="color:#aaa">Pendiente</span>
                        <?php endif; ?>
                        <?php if (!empty($ac['fecha_entrega'])): ?><br><small><?= date('d/m/Y H:i', strtotime($ac['fecha_entrega'])) ?></small><?php endif; ?>
                        <?php if (!empty($ac['evidencia_ruta'])): ?>
                        <br>
                        <a href="<?= BASE_URL . '/' . sanitize($ac['evidencia_ruta']) ?>" target="_blank" class="btn btn-sm btn-secondary" style="margin-top:4px;font-size:10px">
                            Ver evidencia
                        </a>
                        <br><small style="display:block;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= sanitize($ac['evidencia_nombre'] ?? '') ?>">
                            <?= sanitize($ac['evidencia_nombre'] ?? '') ?>
                        </small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-size:13px">
                        <?= ($ac['firma_instructor'] ?? '') ? '<span style="color:var(--verde)" title="Instructor firmó">✍ I</span>' : '<span style="color:#ddd">✍ I</span>' ?>
                        <?= ($ac['firma_aprendiz']   ?? '') ? '<span style="color:var(--verde)" title="Aprendiz firmó"> A</span>' : '<span style="color:#ddd"> A</span>' ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <?php if ($puedeEditar): ?>
                            <button onclick='editAccion(<?= json_encode($ac, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn btn-sm btn-secondary">✎</button>
                            <button onclick='confirmDelete("acciones.php?action=delete&id=<?=$ac['id']?>","esta acción remedial")' class="btn btn-sm btn-danger">✕</button>
                            <?php elseif (($ac['estado_entrega'] ?? '') === 'Entregada' && (!isInstructor() || (int)$ac['instructor_id'] === $instructorActualId)): ?>
                            <button type="button" onclick="abrirRevision(<?= (int)$ac['id'] ?>,'aprobar')" class="btn btn-sm btn-primary">Aprobar</button>
                            <button type="button" onclick="abrirRevision(<?= (int)$ac['id'] ?>,'correccion')" class="btn btn-sm btn-secondary">Correccion</button>
                            <button type="button" onclick="abrirRevision(<?= (int)$ac['id'] ?>,'rechazar')" class="btn btn-sm btn-danger">Rechazar</button>
                            <?php else: ?>
                            <span style="font-size:11px;color:#aaa">Solo lectura</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if ($ac['descripcion']): ?>
                <tr style="background:var(--gris-bg)">
                    <td colspan="10" style="padding:6px 16px 10px 36px;font-size:12px;color:var(--gris-text)">
                        <strong>Descripción:</strong> <?= sanitize($ac['descripcion']) ?>
                        <?php if ($ac['observaciones']): ?> &nbsp;|&nbsp; <strong>Observaciones:</strong> <?= sanitize($ac['observaciones']) ?><?php endif; ?>
                        <?php if (!empty($ac['observacion_entrega'])): ?> &nbsp;|&nbsp; <strong>Entrega aprendiz:</strong> <?= sanitize($ac['observacion_entrega']) ?><?php endif; ?>
                        <?php if (!empty($ac['observacion_revision'])): ?> &nbsp;|&nbsp; <strong>Revision:</strong> <?= sanitize($ac['observacion_revision']) ?><?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages>1): ?>
    <div class="pagination">
        <?php for($i=1;$i<=$pages;$i++): ?><a href="?p=<?=$i?>&q=<?=urlencode($search)?>&pendiente_id=<?=$pendienteFilter?>" class="page-link <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="modalRevision">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <div class="modal-title" id="titleRevision">Revisar evidencia</div>
            <button class="modal-close" onclick="closeModal('modalRevision')">âœ•</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="accion_id" id="rev_accion_id">
            <input type="hidden" name="accion_revision" id="rev_accion">
            <div class="modal-body">
                <div class="form-group full">
                    <label>Observacion para el aprendiz</label>
                    <textarea name="observacion_revision" id="rev_observacion" placeholder="Explique el resultado de la revision o las correcciones necesarias..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalRevision')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar revision</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL ACCIÓN REMEDIAL CON FIRMA -->
<div class="modal-overlay" id="modalAccion">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div class="modal-title" id="titleAccion">Nueva Acción Remedial</div>
            <button class="modal-close" onclick="closeModal('modalAccion')">✕</button>
        </div>
        <form method="POST" id="formAccion" onsubmit="capturarFirmas()" enctype="multipart/form-data">
            <input type="hidden" name="edit_id" id="eaId" value="0">
            <input type="hidden" name="firma_instructor" id="hiddenFirmaInstructor">
            <input type="hidden" name="firma_aprendiz"   id="hiddenFirmaAprendiz">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Pendiente / Aprendiz *</label>
                        <select name="pendiente_id" id="ea_pendiente" required>
                            <option value="">-- Seleccionar pendiente --</option>
                            <?php foreach ($pendientes as $p): ?>
                            <option value="<?=$p['id']?>" <?=$pendienteFilter==$p['id']?'selected':''?>>
                                <?= sanitize($p['aprendiz']) ?> → <?= sanitize($p['competencia']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Instructor que Intervino *</label>
                        <?php if (isInstructor()): ?>
                        <input type="hidden" name="instructor_id" id="ea_instructor" value="<?= (int)$instructorActualId ?>">
                        <input type="text" value="<?= sanitize(trim((getCurrentUser()['nombres'] ?? 'Instructor') . ' ' . (getCurrentUser()['apellidos'] ?? ''))) ?>" disabled>
                        <?php else: ?>
                        <select name="instructor_id" id="ea_instructor" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($instructores as $ins): ?><option value="<?=$ins['id']?>"><?= sanitize($ins['nombre']) ?></option><?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Fecha de la Acción *</label>
                        <input type="date" name="fecha_accion" id="ea_fecha" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha limite del aprendiz</label>
                        <input type="date" name="fecha_limite" id="ea_fecha_limite">
                    </div>
                    <div class="form-group">
                        <label>Tipo de Acción *</label>
                        <select name="tipo_accion" id="ea_tipo" required>
                            <option value="">-- Seleccionar --</option>
                            <option>Refuerzo presencial</option><option>Tutoría individual</option>
                            <option>Taller compensatorio</option><option>Trabajo práctico</option>
                            <option>Evaluación oral</option><option>Otro</option>
                            <option>Sin accion remedial - justificacion</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Resultado</label>
                        <select name="resultado" id="ea_resultado">
                            <option>En proceso</option><option>Aprobado</option><option>No aprobado</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:22px">
                        <input type="checkbox" name="novedad_aprobacion" id="ea_novedad" value="1" style="width:auto">
                        <label for="ea_novedad" style="text-transform:none;font-size:13px">Instructor registró novedad de aprobación</label>
                    </div>
                    <div class="form-group full">
                        <label>Actividades requeridas</label>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;border:1px solid var(--gris-border);border-radius:8px;padding:12px;background:#fafafa">
                            <label style="display:flex;gap:8px;align-items:center;text-transform:none;font-size:13px"><input type="checkbox" name="requiere_trabajo" id="ea_req_trabajo" style="width:auto"> Trabajo escrito</label>
                            <label style="display:flex;gap:8px;align-items:center;text-transform:none;font-size:13px"><input type="checkbox" name="requiere_evidencia" id="ea_req_evidencia" style="width:auto"> Subir evidencia</label>
                            <label style="display:flex;gap:8px;align-items:center;text-transform:none;font-size:13px"><input type="checkbox" name="requiere_sustentacion" id="ea_req_sustentacion" style="width:auto"> Sustentar o exponer</label>
                            <label style="display:flex;gap:8px;align-items:center;text-transform:none;font-size:13px"><input type="checkbox" name="requiere_evaluacion" id="ea_req_evaluacion" style="width:auto"> Presentar evaluacion</label>
                            <label style="display:flex;gap:8px;align-items:center;text-transform:none;font-size:13px"><input type="checkbox" name="requiere_tutoria" id="ea_req_tutoria" style="width:auto"> Asistir a tutoria</label>
                        </div>
                    </div>
                    <div class="form-group full">
                        <label>Otra actividad</label>
                        <input type="text" name="otra_actividad" id="ea_otra_actividad" maxlength="180" placeholder="Ej: socializar proyecto, prueba practica, exposicion grupal...">
                    </div>
                    <div class="form-group full">
                        <label>Indicaciones para el aprendiz</label>
                        <textarea name="indicaciones_aprendiz" id="ea_indicaciones" placeholder="Explique exactamente que debe presentar, como debe sustentarlo y condiciones de la evaluacion..."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Descripción de la Acción *</label>
                        <textarea name="descripcion" id="ea_desc" required placeholder="Describa detalladamente la acción remedial realizada..."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Observaciones Adicionales</label>
                        <textarea name="observaciones" id="ea_obs" placeholder="Observaciones, compromisos, próximos pasos..."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Soporte</label>
                        <input type="file" name="soporte_accion" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx">
                    </div>
                </div>

                <!-- FIRMAS DIGITALES -->
                <div style="margin-top:20px;padding-top:18px;border-top:2px solid var(--gris-border)">
                    <div style="font-family:'Nunito',sans-serif;font-weight:800;font-size:15px;color:var(--negro);margin-bottom:14px">✍ Firmas Digitales <span style="font-size:12px;font-weight:500;color:var(--gris-text)">(opcional)</span></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
                        <div>
                            <label style="display:block;margin-bottom:8px">Firma del Instructor</label>
                            <div class="signature-pad-wrap">
                                <canvas id="padInstructor" width="400" height="160"></canvas>
                                <div class="signature-placeholder" id="phInstructor">Firmar aquí</div>
                            </div>
                            <div class="signature-actions">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="clearPad('padInstructor','hiddenFirmaInstructor','phInstructor')">Limpiar</button>
                                <span id="firmaInstructorOk" style="font-size:12px;color:var(--verde);display:none;font-weight:700">✓ Firmado</span>
                            </div>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:8px">Firma del Aprendiz</label>
                            <div class="signature-pad-wrap">
                                <canvas id="padAprendiz" width="400" height="160"></canvas>
                                <div class="signature-placeholder" id="phAprendiz">Firmar aquí</div>
                            </div>
                            <div class="signature-actions">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="clearPad('padAprendiz','hiddenFirmaAprendiz','phAprendiz')">Limpiar</button>
                                <span id="firmaAprendizOk" style="font-size:12px;color:var(--verde);display:none;font-weight:700">✓ Firmado</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalAccion')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar Acción</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Firma digital ──────────────────────────────────────────
function initPad(canvasId, hiddenId, placeholderId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let drawing = false;
    ctx.strokeStyle = '#1a2e22'; ctx.lineWidth = 2.5; ctx.lineCap = 'round';
    function getPos(e) {
        const r = canvas.getBoundingClientRect();
        const src = e.touches ? e.touches[0] : e;
        return { x:(src.clientX-r.left)*(canvas.width/r.width), y:(src.clientY-r.top)*(canvas.height/r.height) };
    }
    function start(e){ e.preventDefault(); drawing=true; const p=getPos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); document.getElementById(placeholderId).style.display='none'; }
    function draw(e){ if(!drawing)return; e.preventDefault(); const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); }
    function stop(){ if(drawing){ drawing=false; document.getElementById(hiddenId).value=canvas.toDataURL(); const ok=document.getElementById(hiddenId.replace('hidden','firma').replace('Instructor','InstructorOk').replace('Aprendiz','AprendizOk')); if(ok)ok.style.display='inline'; } }
    canvas.addEventListener('mousedown',start); canvas.addEventListener('mousemove',draw); canvas.addEventListener('mouseup',stop); canvas.addEventListener('mouseleave',stop);
    canvas.addEventListener('touchstart',start,{passive:false}); canvas.addEventListener('touchmove',draw,{passive:false}); canvas.addEventListener('touchend',stop);
}
function clearPad(canvasId, hiddenId, placeholderId) {
    const canvas=document.getElementById(canvasId);
    canvas.getContext('2d').clearRect(0,0,canvas.width,canvas.height);
    document.getElementById(hiddenId).value='';
    document.getElementById(placeholderId).style.display='flex';
    const ok=document.getElementById(hiddenId.replace('hidden','firma').replace('Instructor','InstructorOk').replace('Aprendiz','AprendizOk'));
    if(ok) ok.style.display='none';
}
function isCanvasBlank(canvas) {
    // Compare with a fresh blank canvas of same dimensions
    const blank = document.createElement('canvas');
    blank.width = canvas.width; blank.height = canvas.height;
    return canvas.toDataURL() === blank.toDataURL();
}
function capturarFirmas() {
    const ci = document.getElementById('padInstructor');
    const ca = document.getElementById('padAprendiz');
    if (ci) document.getElementById('hiddenFirmaInstructor').value = isCanvasBlank(ci) ? '' : ci.toDataURL();
    if (ca) document.getElementById('hiddenFirmaAprendiz').value   = isCanvasBlank(ca) ? '' : ca.toDataURL();
}
function abrirRevision(accionId, accion) {
    const titulos = {
        aprobar: 'Aprobar evidencia',
        correccion: 'Solicitar correccion',
        rechazar: 'Rechazar evidencia'
    };
    document.getElementById('titleRevision').textContent = titulos[accion] || 'Revisar evidencia';
    document.getElementById('rev_accion_id').value = accionId;
    document.getElementById('rev_accion').value = accion;
    document.getElementById('rev_observacion').value = '';
    openModal('modalRevision');
}
document.addEventListener('DOMContentLoaded', function(){
    initPad('padInstructor','hiddenFirmaInstructor','phInstructor');
    initPad('padAprendiz','hiddenFirmaAprendiz','phAprendiz');
});

function editAccion(d) {
    document.getElementById('titleAccion').textContent    = 'Editar Acción Remedial';
    document.getElementById('eaId').value                = d.id;
    document.getElementById('ea_pendiente').value        = d.pendiente_id;
    document.getElementById('ea_instructor').value       = d.instructor_id;
    document.getElementById('ea_fecha').value            = d.fecha_accion;
    document.getElementById('ea_fecha_limite').value     = d.fecha_limite || '';
    document.getElementById('ea_tipo').value             = d.tipo_accion;
    document.getElementById('ea_resultado').value        = d.resultado;
    document.getElementById('ea_novedad').checked        = d.novedad_aprobacion == 1;
    document.getElementById('ea_req_trabajo').checked     = d.requiere_trabajo == 1;
    document.getElementById('ea_req_evidencia').checked   = d.requiere_evidencia == 1;
    document.getElementById('ea_req_sustentacion').checked= d.requiere_sustentacion == 1;
    document.getElementById('ea_req_evaluacion').checked  = d.requiere_evaluacion == 1;
    document.getElementById('ea_req_tutoria').checked     = d.requiere_tutoria == 1;
    document.getElementById('ea_otra_actividad').value    = d.otra_actividad || '';
    document.getElementById('ea_indicaciones').value      = d.indicaciones_aprendiz || '';
    document.getElementById('ea_desc').value             = d.descripcion || '';
    document.getElementById('ea_obs').value              = d.observaciones || '';
    // Limpiar pads al editar
    clearPad('padInstructor','hiddenFirmaInstructor','phInstructor');
    clearPad('padAprendiz','hiddenFirmaAprendiz','phAprendiz');
    openModal('modalAccion');
}
<?php if ($action==='new'): ?>document.addEventListener('DOMContentLoaded',()=>openModal('modalAccion'));<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
