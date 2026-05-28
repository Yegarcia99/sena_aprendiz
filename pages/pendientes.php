<?php
// pages/pendientes.php - Gestión de Pendientes / Competencias
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
require_once __DIR__ . '/../includes/academico_flujo.php';
requireLogin();
denyIfAprendiz(); // Aprendiz no accede a pendientes
$pageTitle = 'Pendientes de Aprendices';
$db = getDB();
ensureExpedienteSchema($db);
$user = getCurrentUser();
$msg = $err = '';
$ff  = filtroFichas($db, 'a');  // restricción por rol
$action     = $_GET['action'] ?? 'list';
$id         = (int)($_GET['id'] ?? 0);
$aprendizFilter = (int)($_GET['aprendiz_id'] ?? 0);

// Instructor puede reportar casos academicos; la edicion/eliminacion queda para gestor/coordinacion/admin.
$puedeCrear = !isAprendiz();
$puedeEditar = !isInstructor() && !isAprendiz();
$instructorActualId = instructorIdActual($db);

// ── GUARDAR ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$puedeCrear) {
        $err = 'No tiene permisos para registrar o modificar pendientes.';
    } else {
    $resultadosSeleccionados = array_map('intval', $_POST['resultado_ids'] ?? []);
    $data = [
        'aprendiz_id'           => (int)($_POST['aprendiz_id'] ?? 0),
        'competencia_id'        => (int)($_POST['competencia_id'] ?? 0),
        'resultado_id'          => count($resultadosSeleccionados) === 1 ? $resultadosSeleccionados[0] : null,
        'instructor_id'         => isInstructor() ? $instructorActualId : (int)($_POST['instructor_id'] ?? 0),
        'trimestre_ocurrencia'  => (int)($_POST['trimestre_ocurrencia'] ?? 0),
        'fecha_registro'        => $_POST['fecha_registro'] ?? date('Y-m-d'),
        'tipo_caso'             => $_POST['tipo_caso'] ?? 'Academico',
        'motivo'                => trim($_POST['motivo'] ?? ''),
        'debe_repetir_competencia' => isset($_POST['debe_repetir']) ? 1 : 0,
        'estado'                => isInstructor() ? 'Pendiente' : ($_POST['estado'] ?? 'Pendiente'),
        'observaciones'         => trim($_POST['observaciones'] ?? ''),
    ];
    if (!$data['aprendiz_id'] || !$data['competencia_id'] || !$data['instructor_id'] || !$data['trimestre_ocurrencia']) {
        $err = 'Complete todos los campos obligatorios.';
    } else {
        $editId = (int)($_POST['edit_id'] ?? 0);
        if ($editId && !$puedeEditar) {
            $err = 'El instructor solo puede crear reportes nuevos.';
        } elseif ($editId) {
            $stmt = $db->prepare("UPDATE pendientes_aprendices SET aprendiz_id=?,competencia_id=?,resultado_id=?,instructor_id=?,trimestre_ocurrencia=?,fecha_registro=?,tipo_caso=?,motivo=?,debe_repetir_competencia=?,estado=?,observaciones=? WHERE id=?");
            $stmt->execute([...array_values($data), $editId]);
            $msg = 'Pendiente actualizado.';
        } else {
            $gestorStmt = $db->prepare("
                SELECT f.gestor_id
                FROM aprendices a
                JOIN fichas f ON f.id = a.ficha_id
                WHERE a.id = ?
            ");
            $gestorStmt->execute([$data['aprendiz_id']]);
            $gestorId = (int)($gestorStmt->fetchColumn() ?: 0);

            $stmt = $db->prepare("
                INSERT INTO pendientes_aprendices
                (aprendiz_id,competencia_id,resultado_id,instructor_id,gestor_id,trimestre_ocurrencia,fecha_registro,tipo_caso,motivo,debe_repetir_competencia,estado,estado_flujo,instancia_actual,observaciones)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $data['aprendiz_id'],
                $data['competencia_id'],
                $data['resultado_id'],
                $data['instructor_id'],
                $gestorId ?: null,
                $data['trimestre_ocurrencia'],
                $data['fecha_registro'],
                $data['tipo_caso'],
                $data['motivo'],
                $data['debe_repetir_competencia'],
                'Pendiente',
                'Reportado',
                0,
                $data['observaciones'],
            ]);
            $nuevoPendienteId = (int)$db->lastInsertId();
            if ($resultadosSeleccionados) {
                $rel = $db->prepare("INSERT IGNORE INTO pendiente_resultados (pendiente_id, resultado_id) VALUES (?, ?)");
                foreach ($resultadosSeleccionados as $rid) {
                    $rel->execute([$nuevoPendienteId, $rid]);
                }
            }
            registrarEventoAcademico($db, $nuevoPendienteId, $data['aprendiz_id'], 'Reporte academico', 'Reportado', $data['motivo']);
            notificarReporteAcademico($db, $nuevoPendienteId);
            $msg = 'Pendiente registrado correctamente.';
        }
        $action = 'list';
    }
    } // end else puedeEscribir
}

// ── ELIMINAR ─────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    verifyCsrf();
    if (!$puedeEditar) {
        $err = 'No tiene permisos para eliminar pendientes.';
        $action = 'list';
    } else {
    try {
        $db->beginTransaction();
        // Eliminar dependencias antes de eliminar el pendiente
        $db->prepare("DELETE FROM acciones_remediales WHERE pendiente_id=?")->execute([$id]);
        $db->prepare("DELETE FROM pendiente_resultados WHERE pendiente_id=?")->execute([$id]);
        $db->prepare("DELETE FROM pendientes_aprendices WHERE id=?")->execute([$id]);
        $db->commit();
        $msg = 'Pendiente eliminado correctamente.';
    } catch (PDOException $e) {
        $db->rollBack();
        $err = 'Error al eliminar el pendiente: ' . $e->getMessage();
    }
    $action = 'list';
    }
}

// Datos auxiliares
$aprSql = "SELECT a.id, CONCAT(a.apellidos,', ',a.nombres) AS nombre, a.documento FROM aprendices a WHERE a.estado='Activo'";
$aprParams = [];
if (!empty($ff['params'])) {
    $aprSql .= " AND " . $ff['sql'];
    $aprParams = $ff['params'];
}
$aprSql .= " ORDER BY a.apellidos";
$aprStmt = $db->prepare($aprSql);
$aprStmt->execute($aprParams);
$aprendices = $aprStmt->fetchAll();
$instructores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM instructores WHERE activo=1 ORDER BY nombres")->fetchAll();
$competencias = $db->query("SELECT c.id, c.nombre, c.trimestre, p.nombre AS programa FROM competencias c JOIN programas p ON p.id=c.programa_id WHERE c.activa=1 ORDER BY c.nombre")->fetchAll();

// ── LISTADO ───────────────────────────────────────────────────
$whereC  = $ff['sql'] !== '1=1' ? '(' . $ff['sql'] . ')' : '1=1';
$params  = $ff['params'];
$search  = trim($_GET['q'] ?? '');
$filtroE = $_GET['estado'] ?? '';
if ($search) {
    $whereC .= " AND (CONCAT(a.nombres,' ',a.apellidos) LIKE ? OR a.documento LIKE ? OR c.nombre LIKE ?)";
    $params  = array_merge($params, array_fill(0, 3, "%$search%"));
}
if ($filtroE) {
    $whereC .= " AND pa.estado=?";
    $params[] = $filtroE;
}
if ($aprendizFilter) {
    $whereC .= " AND pa.aprendiz_id=?";
    $params[] = $aprendizFilter;
}

$page   = max(1,(int)($_GET['p']??1));
$limit  = 20;
$offset = ($page-1)*$limit;

$cntStmt = $db->prepare("SELECT COUNT(*) FROM pendientes_aprendices pa JOIN aprendices a ON a.id=pa.aprendiz_id JOIN competencias c ON c.id=pa.competencia_id WHERE $whereC");
$cntStmt->execute($params);
$total = $cntStmt->fetchColumn();
$pages = ceil($total/$limit);

$stmt = $db->prepare("
    SELECT pa.*,
           CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre, a.documento,
           c.nombre AS competencia_nombre, c.trimestre AS trimestre_comp,
           CONCAT(i.nombres,' ',i.apellidos) AS instructor_nombre,
           ra.nombre AS resultado_nombre,
           (SELECT GROUP_CONCAT(r2.nombre SEPARATOR ' / ')
            FROM pendiente_resultados pr2 JOIN resultados_aprendizaje r2 ON r2.id=pr2.resultado_id
            WHERE pr2.pendiente_id=pa.id) AS resultados_multi,
           (SELECT GROUP_CONCAT(pr2.resultado_id)
            FROM pendiente_resultados pr2 WHERE pr2.pendiente_id=pa.id) AS resultado_ids_multi,
           f.numero_ficha,
           (SELECT COUNT(*) FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id) AS num_acciones
    FROM pendientes_aprendices pa
    JOIN aprendices a ON a.id=pa.aprendiz_id
    JOIN fichas f ON f.id=a.ficha_id
    JOIN competencias c ON c.id=pa.competencia_id
    JOIN instructores i ON i.id=pa.instructor_id
    LEFT JOIN resultados_aprendizaje ra ON ra.id=pa.resultado_id
    WHERE $whereC
    ORDER BY pa.estado='Pendiente' DESC, pa.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$pendientes = $stmt->fetchAll();

// Si hay filtro de aprendiz, obtener su nombre
$aprendizNombre = '';
if ($aprendizFilter) {
    $tmp = $db->prepare("SELECT CONCAT(nombres,' ',apellidos) AS n FROM aprendices WHERE id=?");
    $tmp->execute([$aprendizFilter]);
    $aprendizNombre = $tmp->fetchColumn();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Pendientes</div>
        <div class="page-subtitle">
            <?php if ($aprendizNombre): ?>
            Mostrando pendientes de: <strong><?= sanitize($aprendizNombre) ?></strong>
            <a href="pendientes.php" style="font-size:12px;color:var(--verde);margin-left:8px">Ver todos</a>
            <?php else: ?>
            Competencias y resultados pendientes de aprendices
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/ajax/exportar_excel.php?tipo=pendientes" class="btn btn-excel">↓ Exportar Excel</a>
        <?php if ($puedeCrear): ?>
        <button class="btn btn-primary" onclick="openModal('modalPendiente')">+ Registrar Pendiente</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<!-- FILTROS -->
<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="hidden" name="aprendiz_id" value="<?= $aprendizFilter ?>">
        <input type="text" name="q" class="search-input" placeholder="Buscar aprendiz, competencia..." value="<?= sanitize($search) ?>">
        <select name="estado" class="search-input" style="min-width:160px">
            <option value="">Todos los estados</option>
            <?php foreach (['Pendiente','En proceso','No aprobado','Primera instancia','Segunda instancia','Listo para comite','Superado','Remitido a comité'] as $e): ?>
            <option value="<?=$e?>" <?=$filtroE===$e?'selected':''?>><?=$e?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
        <a href="pendientes.php" class="btn btn-sm" style="background:#eee;color:#666">Limpiar</a>
    </form>
</div>

<!-- TABLA -->
<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Registro de Pendientes (<?= $total ?>)</div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Aprendiz</th>
                    <th>Ficha</th>
                    <th>Competencia / Resultado</th>
                    <th>Instructor</th>
                    <th>Trimestre</th>
                    <th>Repite</th>
                    <th>Estado</th>
                    <th>Acciones Rem.</th>
                    <th>Opciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pendientes)): ?>
                <tr><td colspan="9">
                    <div class="empty-state">
                        <div class="icon">◎</div>
                        <p>No hay pendientes registrados.</p>
                    </div>
                </td></tr>
            <?php else: foreach ($pendientes as $p):
                $estadoClass = match($p['estado']) {
                    'Pendiente'         => 'badge-pendiente',
                    'En proceso'        => 'badge-proceso',
                    'Superado'          => 'badge-superado',
                    'Remitido a comité' => 'badge-comite',
                    default             => ''
                };
            ?>
                <tr>
                    <td>
                        <strong><?= sanitize($p['aprendiz_nombre']) ?></strong><br>
                        <small style="color:#888"><?= sanitize($p['documento']) ?></small>
                    </td>
                    <td><?= sanitize($p['numero_ficha']) ?></td>
                    <td>
                        <strong><?= sanitize($p['competencia_nombre']) ?></strong>
                        <?php
                        $resDisplay = $p['resultados_multi'] ?? $p['resultado_nombre'] ?? '';
                        if ($resDisplay): ?>
                        <br><small style="color:#555">↳ <?= sanitize($resDisplay) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px"><?= sanitize($p['instructor_nombre']) ?></td>
                    <td style="text-align:center"><?= $p['trimestre_ocurrencia'] ?>° trim.</td>
                    <td style="text-align:center">
                        <?= $p['debe_repetir_competencia'] ? '<span style="color:var(--rojo);font-weight:700">Sí</span>' : '<span style="color:#aaa">No</span>' ?>
                    </td>
                    <td><span class="badge <?= $estadoClass ?>"><?= sanitize($p['estado']) ?></span></td>
                    <td style="text-align:center">
                        <a href="acciones.php?pendiente_id=<?= $p['id'] ?>" style="font-weight:700;color:var(--verde-dark)">
                            <?= $p['num_acciones'] ?> acción<?= $p['num_acciones']!==1?'es':'' ?>
                        </a>
                        <?php if ($puedeCrear): ?>
                        <br>
                        <a href="acciones.php?action=new&pendiente_id=<?= $p['id'] ?>" class="btn btn-sm btn-primary" style="margin-top:4px;font-size:10px">+ Agregar</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="expediente.php?aprendiz_id=<?= $p['aprendiz_id'] ?>&pendiente_id=<?= $p['id'] ?>" class="btn btn-sm btn-primary">Exp.</a>
                            <?php if ($puedeEditar): ?>
                            <button onclick='editPendiente(<?= json_encode($p, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn btn-sm btn-secondary">✎</button>
                            <button onclick='confirmDelete("pendientes.php?action=delete&id=<?= $p['id'] ?>","pendiente de <?= sanitize($p['aprendiz_nombre']) ?>")'
                                    class="btn btn-sm btn-danger">✕</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php for ($i=1;$i<=$pages;$i++): ?>
        <a href="?p=<?=$i?>&q=<?=urlencode($search)?>&estado=<?=urlencode($filtroE)?>&aprendiz_id=<?=$aprendizFilter?>" class="page-link <?=$i===$page?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL PENDIENTE -->
<div class="modal-overlay" id="modalPendiente">
    <div class="modal" style="max-width:700px">
        <div class="modal-header">
            <div class="modal-title" id="titlePendiente">Registrar Pendiente</div>
            <button class="modal-close" onclick="closeModal('modalPendiente')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="edit_id" id="epId" value="0">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Aprendiz *</label>
                        <select name="aprendiz_id" id="ep_aprendiz" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($aprendices as $ap): ?>
                            <option value="<?=$ap['id']?>" <?=$aprendizFilter==$ap['id']?'selected':''?>><?= sanitize($ap['nombre']) ?> (<?= sanitize($ap['documento']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Instructor Responsable *</label>
                        <?php if (isInstructor()): ?>
                        <input type="hidden" name="instructor_id" id="ep_instructor" value="<?= (int)$instructorActualId ?>">
                        <input type="text" value="<?= sanitize(trim(($user['nombres'] ?? 'Instructor') . ' ' . ($user['apellidos'] ?? ''))) ?>" disabled>
                        <?php else: ?>
                        <select name="instructor_id" id="ep_instructor" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($instructores as $ins): ?>
                            <option value="<?=$ins['id']?>"><?= sanitize($ins['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Competencia *</label>
                        <select name="competencia_id" id="ep_competencia" required onchange="cargarResultados(this.value)">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($competencias as $c): ?>
                            <option value="<?=$c['id']?>"><?= sanitize($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Resultados de Aprendizaje <span style="font-weight:400;color:var(--gris-text)">(selecciona uno o varios)</span></label>
                        <div id="resultadosChecklist" style="border:1px solid var(--gris-border);border-radius:8px;padding:10px;max-height:160px;overflow-y:auto;background:#fafafa">
                            <p style="color:#bbb;font-size:12px;margin:0" id="resultadosPlaceholder">— Primero selecciona una competencia —</p>
                        </div>
                        <span id="resultadoInfo" style="font-size:11px;color:var(--gris-text);display:none"></span>
                    </div>
                    <div class="form-group">
                        <label>Trimestre en que ocurrió *</label>
                        <select name="trimestre_ocurrencia" id="ep_trimestre" required>
                            <?php for ($t=1;$t<=8;$t++): ?><option value="<?=$t?>"><?=$t?>° Trimestre</option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha de Registro *</label>
                        <input type="date" name="fecha_registro" id="ep_fecha" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tipo de caso</label>
                        <select name="tipo_caso" id="ep_tipo_caso">
                            <option>Academico</option>
                            <option>Inasistencia</option>
                            <option>Disciplinario</option>
                            <option>Desercion</option>
                            <option>Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <?php if (isInstructor()): ?>
                        <input type="text" value="Pendiente" disabled>
                        <input type="hidden" name="estado" id="ep_estado" value="Pendiente">
                        <?php else: ?>
                        <select name="estado" id="ep_estado">
                            <option>Pendiente</option>
                            <option>En proceso</option>
                            <option>Superado</option>
                            <option>Remitido a comité</option>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:22px">
                        <input type="checkbox" name="debe_repetir" id="ep_repetir" value="1" style="width:auto">
                        <label for="ep_repetir" style="text-transform:none;font-size:13px">¿Debe repetir la competencia?</label>
                    </div>
                    <div class="form-group full">
                        <label>Motivo del Pendiente</label>
                        <textarea name="motivo" id="ep_motivo" placeholder="Describa el motivo por el cual el aprendiz tiene este pendiente..."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Observaciones</label>
                        <textarea name="observaciones" id="ep_obs" placeholder="Observaciones adicionales..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalPendiente')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Pendiente</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Carga dinámica de Resultados de Aprendizaje ───────────
function cargarResultados(competenciaId, selectedIds = []) {
    const container = document.getElementById('resultadosChecklist');
    const info      = document.getElementById('resultadoInfo');
    const ph        = document.getElementById('resultadosPlaceholder');

    container.innerHTML = '<p style="color:#aaa;font-size:12px;margin:0">Cargando...</p>';
    info.style.display  = 'none';

    if (!competenciaId) {
        container.innerHTML = '<p style="color:#bbb;font-size:12px;margin:0" id="resultadosPlaceholder">— Primero selecciona una competencia —</p>';
        return;
    }

    if (typeof selectedIds === 'string') {
        selectedIds = selectedIds ? selectedIds.split(',').map(x => x.trim()) : [];
    }
    selectedIds = selectedIds.map(String);

    fetch('<?= BASE_URL ?>/ajax/resultados.php?competencia_id=' + competenciaId)
        .then(r => r.json())
        .then(data => {
            if (data.length === 0) {
                container.innerHTML = '<p style="color:#bbb;font-size:12px;margin:0">— Esta competencia no tiene resultados de aprendizaje —</p>';
                info.textContent = '⚠ Ve a Competencias para agregar resultados de aprendizaje.';
                info.style.display = 'block';
            } else {
                container.innerHTML = '';
                data.forEach(r => {
                    const label = document.createElement('label');
                    label.style.cssText = 'display:flex;align-items:flex-start;gap:8px;padding:5px 2px;font-size:13px;cursor:pointer;border-bottom:1px solid #f0f0f0';
                    const cb = document.createElement('input');
                    cb.type  = 'checkbox';
                    cb.name  = 'resultado_ids[]';
                    cb.value = r.id;
                    cb.style.cssText = 'width:auto;margin-top:2px;accent-color:var(--verde);flex-shrink:0';
                    cb.checked = selectedIds.includes(String(r.id));
                    label.appendChild(cb);
                    const span = document.createElement('span');
                    span.textContent = r.nombre;
                    label.appendChild(span);
                    container.appendChild(label);
                });
                info.textContent = data.length + ' resultado(s) disponible(s)';
                info.style.display = 'block';
            }
        })
        .catch(() => {
            container.innerHTML = '<p style="color:var(--rojo);font-size:12px;margin:0">Error al cargar resultados</p>';
        });
}

function editPendiente(d) {
    document.getElementById('titlePendiente').textContent = 'Editar Pendiente';
    document.getElementById('epId').value          = d.id;
    document.getElementById('ep_aprendiz').value   = d.aprendiz_id;
    document.getElementById('ep_instructor').value = d.instructor_id;
    document.getElementById('ep_trimestre').value  = d.trimestre_ocurrencia;
    document.getElementById('ep_fecha').value      = d.fecha_registro;
    document.getElementById('ep_tipo_caso').value  = d.tipo_caso || 'Academico';
    document.getElementById('ep_estado').value     = d.estado;
    document.getElementById('ep_motivo').value     = d.motivo || '';
    document.getElementById('ep_obs').value        = d.observaciones || '';
    document.getElementById('ep_repetir').checked  = d.debe_repetir_competencia == 1;
    // Cargar competencia y luego resultados con el resultado ya seleccionado
    document.getElementById('ep_competencia').value = d.competencia_id;
    cargarResultados(d.competencia_id, d.resultado_ids_multi || (d.resultado_id ? String(d.resultado_id) : ''));
    openModal('modalPendiente');
}

<?php if ($action==='new'): ?>
document.addEventListener('DOMContentLoaded', () => openModal('modalPendiente'));
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
