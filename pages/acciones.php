<?php
// pages/acciones.php - Acciones Remediales con firma digital
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
requireLogin();
$pageTitle = 'Acciones Remediales';
$db  = getDB();
ensureExpedienteSchema($db);
$msg = $err = '';
$ff  = filtroFichas($db, 'a');
$action          = $_GET['action'] ?? 'list';
$id              = (int)($_GET['id'] ?? 0);
$pendienteFilter = (int)($_GET['pendiente_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'pendiente_id'       => (int)($_POST['pendiente_id'] ?? 0),
        'instructor_id'      => (int)($_POST['instructor_id'] ?? 0),
        'fecha_accion'       => $_POST['fecha_accion'] ?? date('Y-m-d'),
        'tipo_accion'        => $_POST['tipo_accion'] ?? '',
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

        if ($editId) {
            if ($tieneColumnasFirma) {
                $stmt = $db->prepare("UPDATE acciones_remediales SET pendiente_id=?,instructor_id=?,fecha_accion=?,tipo_accion=?,descripcion=?,resultado=?,novedad_aprobacion=?,observaciones=?,firma_instructor=?,firma_aprendiz=? WHERE id=?");
                $stmt->execute([...array_values($data), $firmaInstructor ?: null, $firmaAprendiz ?: null, $editId]);
            } else {
                $stmt = $db->prepare("UPDATE acciones_remediales SET pendiente_id=?,instructor_id=?,fecha_accion=?,tipo_accion=?,descripcion=?,resultado=?,novedad_aprobacion=?,observaciones=? WHERE id=?");
                $stmt->execute([...array_values($data), $editId]);
            }
            $msg = 'Acción remedial actualizada.';
        } else {
            if ($tieneColumnasFirma) {
                $stmt = $db->prepare("INSERT INTO acciones_remediales (pendiente_id,instructor_id,fecha_accion,tipo_accion,descripcion,resultado,novedad_aprobacion,observaciones,firma_instructor,firma_aprendiz) VALUES(?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([...array_values($data), $firmaInstructor ?: null, $firmaAprendiz ?: null]);
            } else {
                $stmt = $db->prepare("INSERT INTO acciones_remediales (pendiente_id,instructor_id,fecha_accion,tipo_accion,descripcion,resultado,novedad_aprobacion,observaciones) VALUES(?,?,?,?,?,?,?,?)");
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
                $db->prepare("UPDATE pendientes_aprendices SET estado='Superado' WHERE id=?")->execute([$data['pendiente_id']]);
            } elseif ($data['resultado'] === 'No aprobado') {
                $db->prepare("UPDATE pendientes_aprendices SET estado='No aprobado' WHERE id=?")->execute([$data['pendiente_id']]);
            } else {
                $db->prepare("UPDATE pendientes_aprendices SET estado='En proceso' WHERE id=? AND estado='Pendiente'")->execute([$data['pendiente_id']]);
            }
            $msg = 'Acción remedial registrada correctamente.';
        }
        $action = 'list';
    }
}

if ($action === 'delete' && $id) {
    verifyCsrf();
    try {
        $db->prepare("DELETE FROM acciones_remediales WHERE id=?")->execute([$id]);
        $msg = 'Acción remedial eliminada correctamente.';
    } catch (PDOException $e) {
        $err = 'Error al eliminar la acción: ' . $e->getMessage();
    }
    $action = 'list';
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
           CONCAT(i.nombres,' ',i.apellidos) AS instructor_nombre
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
        <button class="btn btn-primary" onclick="openModal('modalAccion')">+ Nueva Acción</button>
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
            <thead><tr><th>Fecha</th><th>Aprendiz</th><th>Ficha</th><th>Competencia</th><th>Instructor</th><th>Tipo</th><th>Resultado</th><th>Firmas</th><th>Opciones</th></tr></thead>
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
                    <td style="text-align:center;font-size:13px">
                        <?= ($ac['firma_instructor'] ?? '') ? '<span style="color:var(--verde)" title="Instructor firmó">✍ I</span>' : '<span style="color:#ddd">✍ I</span>' ?>
                        <?= ($ac['firma_aprendiz']   ?? '') ? '<span style="color:var(--verde)" title="Aprendiz firmó"> A</span>' : '<span style="color:#ddd"> A</span>' ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <button onclick='editAccion(<?= json_encode($ac, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn btn-sm btn-secondary">✎</button>
                            <button onclick='confirmDelete("acciones.php?action=delete&id=<?=$ac['id']?>","esta acción remedial")' class="btn btn-sm btn-danger">✕</button>
                        </div>
                    </td>
                </tr>
                <?php if ($ac['descripcion']): ?>
                <tr style="background:var(--gris-bg)">
                    <td colspan="9" style="padding:6px 16px 10px 36px;font-size:12px;color:var(--gris-text)">
                        <strong>Descripción:</strong> <?= sanitize($ac['descripcion']) ?>
                        <?php if ($ac['observaciones']): ?> &nbsp;|&nbsp; <strong>Observaciones:</strong> <?= sanitize($ac['observaciones']) ?><?php endif; ?>
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
                        <select name="instructor_id" id="ea_instructor" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($instructores as $ins): ?><option value="<?=$ins['id']?>"><?= sanitize($ins['nombre']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha de la Acción *</label>
                        <input type="date" name="fecha_accion" id="ea_fecha" value="<?= date('Y-m-d') ?>" required>
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
    document.getElementById('ea_tipo').value             = d.tipo_accion;
    document.getElementById('ea_resultado').value        = d.resultado;
    document.getElementById('ea_novedad').checked        = d.novedad_aprobacion == 1;
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
