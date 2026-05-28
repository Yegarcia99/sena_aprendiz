<?php
// pages/asistente_disciplinario.php - Asistente guiado para casos disciplinarios
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
requireLogin();
denyIfAprendiz(); // Aprendiz no tiene acceso a esta página
denyIfInstructorOrAprendiz();

$pageTitle = 'Asistente Disciplinario';
$db = getDB();
ensureExpedienteSchema($db);

$msg = $err = '';
$user = getCurrentUser();

function postBoolDisc(string $key): int {
    return isset($_POST[$key]) ? 1 : 0;
}
function firstUploadedFileDisc(string $field): ?array {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    return $_FILES[$field];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $db->beginTransaction();

        $aprendizId  = (int)($_POST['aprendiz_id'] ?? 0);
        $instructorId = (int)($_POST['instructor_id'] ?? 0);
        $tipoCaso    = $_POST['tipo_caso'] ?? 'Disciplinario';
        $gravedad    = $_POST['gravedad'] ?? 'Falta leve';
        $estadoPendiente = $_POST['estado_pendiente'] ?? 'Pendiente';
        $trimestre   = (int)($_POST['trimestre_ocurrencia'] ?? 1);
        $motivo      = trim($_POST['motivo'] ?? '');
        $observaciones = trim($_POST['observaciones_pendiente'] ?? '');

        if (!$aprendizId || !$instructorId || !$motivo) {
            throw new RuntimeException('Seleccione aprendiz, instructor/responsable y describa la falta.');
        }

        $stmt = $db->prepare("
            INSERT INTO pendientes_aprendices
            (aprendiz_id, competencia_id, resultado_id, instructor_id, trimestre_ocurrencia, fecha_registro, tipo_caso, motivo, debe_repetir_competencia, estado, observaciones)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $aprendizId, null, null, $instructorId,
            $trimestre,
            $_POST['fecha_registro'] ?? date('Y-m-d'),
            $tipoCaso, $motivo, 0, $estadoPendiente,
            trim($observaciones . "\nGravedad: " . $gravedad),
        ]);
        $pendienteId = (int)$db->lastInsertId();

        $huboAccion      = $_POST['hubo_accion'] ?? 'Si';
        $accionResultado = $_POST['resultado_accion'] ?? 'En proceso';
        $accionTipo      = $huboAccion === 'Si' ? ($_POST['tipo_accion'] ?? 'Llamado de atención verbal') : 'Sin medida - justificacion';
        $accionDesc      = trim($_POST['descripcion_accion'] ?? '');
        $justificacion   = trim($_POST['justificacion_sin_accion'] ?? '');

        if ($huboAccion === 'Si' && $accionDesc === '') throw new RuntimeException('Describa la medida adoptada o el descargo.');
        if ($huboAccion !== 'Si' && $justificacion === '') throw new RuntimeException('Justifique por qué no se tomó medida aún.');

        $stmt = $db->prepare("
            INSERT INTO acciones_remediales
            (pendiente_id, instructor_id, fecha_accion, tipo_accion, descripcion, resultado, novedad_aprobacion, observaciones, firma_instructor, firma_aprendiz)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $pendienteId, $instructorId,
            $_POST['fecha_accion'] ?? date('Y-m-d'),
            $accionTipo,
            $huboAccion === 'Si' ? $accionDesc : $justificacion,
            $accionResultado,
            postBoolDisc('novedad_aprobacion'),
            trim($_POST['observaciones_accion'] ?? ''),
            $_POST['firma_instructor'] ?: null,
            $_POST['firma_aprendiz'] ?: null,
        ]);
        $accionId = (int)$db->lastInsertId();

        if ($file = firstUploadedFileDisc('soporte_accion')) {
            guardarSoporteExpediente($db, $file, [
                'aprendiz_id'  => $aprendizId,
                'pendiente_id' => $pendienteId,
                'accion_id'    => $accionId,
                'tipo_soporte' => 'Soporte disciplinario',
                'descripcion'  => trim($_POST['observaciones_accion'] ?? ''),
                'subido_por'   => $user['id'] ?? null,
            ]);
        }

        if ($accionResultado === 'Comprometido' || $accionResultado === 'Aprobado') {
            $db->prepare("UPDATE pendientes_aprendices SET estado='Superado' WHERE id=?")->execute([$pendienteId]);
        } elseif ($accionResultado === 'No aprobado') {
            $db->prepare("UPDATE pendientes_aprendices SET estado='Listo para comite' WHERE id=?")->execute([$pendienteId]);
        } else {
            $db->prepare("UPDATE pendientes_aprendices SET estado='En proceso' WHERE id=?")->execute([$pendienteId]);
        }

        if ($file = firstUploadedFileDisc('soporte_general')) {
            guardarSoporteExpediente($db, $file, [
                'aprendiz_id'  => $aprendizId,
                'pendiente_id' => $pendienteId,
                'tipo_soporte' => $_POST['tipo_soporte_general'] ?? 'Soporte disciplinario',
                'descripcion'  => trim($_POST['descripcion_soporte_general'] ?? ''),
                'subido_por'   => $user['id'] ?? null,
            ]);
        }

        if (isset($_POST['registrar_notificacion'])) {
            $correo = trim($_POST['correo_destino'] ?? '');
            $asunto = trim($_POST['asunto_notificacion'] ?? '');
            $msg_not = trim($_POST['mensaje_notificacion'] ?? '');
            if ($correo && $asunto && $msg_not) {
                $db->prepare("
                    INSERT INTO notificaciones (aprendiz_id, pendiente_id, referencia_tipo, referencia_id, correo_destino, asunto, mensaje, estado_envio, enviado_por)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ")->execute([$aprendizId, $pendienteId, 'Asistente disciplinario', $pendienteId, $correo, $asunto, $msg_not, 'Registrada', $user['id'] ?? null]);
            }
        }

        $db->commit();
        $msg = 'Caso disciplinario registrado. Revise el expediente para ver la trazabilidad completa.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $err = $e->getMessage();
    }
}

$aprendices  = $db->query("SELECT a.id, CONCAT(a.apellidos,', ',a.nombres) AS nombre, a.documento, a.email, f.numero_ficha FROM aprendices a JOIN fichas f ON f.id=a.ficha_id WHERE a.estado='Activo' ORDER BY a.apellidos,a.nombres")->fetchAll();
$instructores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM instructores WHERE activo=1 ORDER BY nombres")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="border-left:4px solid var(--naranja)">
    <div>
        <div class="page-title">⚠️ Asistente Disciplinario</div>
        <div class="page-subtitle">Registro guiado de faltas, medidas, compromisos y notificaciones disciplinarias</div>
    </div>
    <a href="expediente.php" class="btn btn-secondary">Ver expedientes</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<!-- Ruta del caso -->
<div class="table-card" style="margin-bottom:18px">
    <div class="table-card-header" style="background:var(--naranja-soft);border-bottom:0.5px solid var(--naranja-line)">
        <div class="table-card-title" style="color:#7a5500">⚠️ Ruta del caso disciplinario</div>
    </div>
    <div style="padding:14px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
        <div class="alert alert-warning" style="margin:0;font-size:12px">1. Datos de la falta</div>
        <div class="alert alert-warning" style="margin:0;font-size:12px">2. Medida o descargo</div>
        <div class="alert alert-warning" style="margin:0;font-size:12px">3. Compromisos y firmas</div>
        <div class="alert alert-info"    style="margin:0;font-size:12px">4. Soportes y notificación</div>
        <div class="alert alert-info"    style="margin:0;font-size:12px">5. Expediente → Comité</div>
    </div>
</div>

<div class="case-shell">
    <div class="case-nav">
        <a href="#disc-datos">1. Datos de la falta</a>
        <a href="#disc-medida">2. Medida o descargo</a>
        <a href="#disc-compromisos">3. Compromisos y firmas</a>
        <a href="#disc-soportes">4. Soportes y notificación</a>
        <a href="#disc-guardar">5. Guardar</a>
    </div>

    <form method="POST" enctype="multipart/form-data" onsubmit="capturarFirmasDisc()">
        <?= csrfField() ?>

        <!-- 1. Datos de la falta -->
        <div class="table-card case-section" id="disc-datos" style="margin-bottom:18px">
            <div class="table-card-header" style="border-left:3px solid var(--naranja)">
                <div>
                    <div class="table-card-title">1. Datos de la falta disciplinaria</div>
                    <div class="section-kicker">Identifique al aprendiz, el tipo de falta y las circunstancias.</div>
                </div>
            </div>
            <div class="professional-card-body">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Aprendiz *</label>
                        <select name="aprendiz_id" id="aprendiz_id" required onchange="llenarCorreo()">
                            <option value="">-- Seleccionar aprendiz --</option>
                            <?php foreach ($aprendices as $ap): ?>
                            <option value="<?= $ap['id'] ?>" data-email="<?= sanitize($ap['email'] ?? '') ?>">
                                <?= sanitize($ap['nombre']) ?> — <?= sanitize($ap['documento']) ?> / Ficha <?= sanitize($ap['numero_ficha']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tipo de falta</label>
                        <select name="tipo_caso">
                            <option value="Disciplinario">Disciplinario general</option>
                            <option value="Convivencia">Convivencia</option>
                            <option value="Falta disciplinaria">Falta disciplinaria grave</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gravedad</label>
                        <select name="gravedad">
                            <option>Falta leve</option>
                            <option>Falta grave</option>
                            <option>Caso excepcional</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado inicial</label>
                        <select name="estado_pendiente">
                            <option>Pendiente</option>
                            <option>En proceso</option>
                            <option>Listo para comite</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Instructor / Responsable *</label>
                        <select name="instructor_id" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($instructores as $i): ?>
                            <option value="<?= $i['id'] ?>"><?= sanitize($i['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Trimestre</label>
                        <select name="trimestre_ocurrencia">
                            <?php for ($t=1;$t<=8;$t++): ?>
                            <option value="<?= $t ?>"><?= $t ?> Trimestre</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha de registro</label>
                        <input type="date" name="fecha_registro" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group full">
                        <label>Descripción de la falta *</label>
                        <textarea name="motivo" required placeholder="Describa detalladamente la falta: qué ocurrió, cuándo, dónde y quiénes estuvieron involucrados."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Observaciones adicionales</label>
                        <textarea name="observaciones_pendiente" placeholder="Antecedentes, testigos, contexto relevante para coordinación o comité."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Medida o descargo -->
        <div class="table-card case-section" id="disc-medida" style="margin-bottom:18px">
            <div class="table-card-header" style="border-left:3px solid var(--naranja)">
                <div>
                    <div class="table-card-title">2. Medida adoptada o descargo</div>
                    <div class="section-kicker">Registre la medida tomada o el descargo presentado por el aprendiz.</div>
                </div>
            </div>
            <div class="professional-card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>¿Se tomó medida o descargo?</label>
                        <select name="hubo_accion" id="hubo_accion" onchange="toggleMedida()">
                            <option value="Si">Sí</option>
                            <option value="No">No (pendiente)</option>
                        </select>
                    </div>
                    <div class="form-group medida-si">
                        <label>Tipo de medida</label>
                        <select name="tipo_accion">
                            <option>Llamado de atención verbal</option>
                            <option>Llamado de atención escrito</option>
                            <option>Compromiso de convivencia</option>
                            <option>Descargo del aprendiz</option>
                            <option>Citación a padres / acudiente</option>
                            <option>Suspensión temporal</option>
                            <option>Remisión a comité</option>
                            <option>Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha de la medida</label>
                        <input type="date" name="fecha_accion" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Resultado / Estado</label>
                        <select name="resultado_accion">
                            <option>En proceso</option>
                            <option>Comprometido</option>
                            <option>Aprobado</option>
                            <option>No aprobado</option>
                        </select>
                    </div>
                    <div class="form-group full medida-si">
                        <label>Descripción de la medida o descargo</label>
                        <textarea name="descripcion_accion" placeholder="Detalle la medida adoptada, el descargo presentado o el acuerdo alcanzado."></textarea>
                    </div>
                    <div class="form-group full medida-no" style="display:none">
                        <label>Justificación — ¿Por qué no se tomó medida aún?</label>
                        <textarea name="justificacion_sin_accion" placeholder="Ej: se citó al aprendiz para el día siguiente; pendiente de descargo."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Observaciones</label>
                        <textarea name="observaciones_accion" placeholder="Compromisos, fechas de seguimiento o detalles para coordinación."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Soporte de la medida o descargo</label>
                        <input type="file" name="soporte_accion" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx">
                    </div>
                    <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="novedad_aprobacion" value="1" style="width:auto">
                        <label style="text-transform:none">Se registró novedad formal en el sistema</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Compromisos y firmas -->
        <div class="table-card case-section" id="disc-compromisos" style="margin-bottom:18px">
            <div class="table-card-header" style="border-left:3px solid var(--naranja)">
                <div>
                    <div class="table-card-title">3. Compromisos de convivencia y firmas</div>
                    <div class="section-kicker">Registre los compromisos adquiridos y las firmas de las partes.</div>
                </div>
            </div>
            <div class="professional-card-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
                    <div>
                        <label>Firma instructor / responsable</label>
                        <input type="hidden" name="firma_instructor" id="firma_instructor">
                        <div class="signature-pad-wrap"><canvas id="padInstructor" width="500" height="180"></canvas><div class="signature-placeholder" id="phInstructor">Firmar aquí</div></div>
                        <div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPad('padInstructor','firma_instructor','phInstructor')">Limpiar</button></div>
                    </div>
                    <div>
                        <label>Firma aprendiz</label>
                        <input type="hidden" name="firma_aprendiz" id="firma_aprendiz">
                        <div class="signature-pad-wrap"><canvas id="padAprendiz" width="500" height="180"></canvas><div class="signature-placeholder" id="phAprendiz">Firmar aquí</div></div>
                        <div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPad('padAprendiz','firma_aprendiz','phAprendiz')">Limpiar</button></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Soportes y notificación -->
        <div class="table-card case-section" id="disc-soportes" style="margin-bottom:18px">
            <div class="table-card-header" style="border-left:3px solid var(--naranja)">
                <div>
                    <div class="table-card-title">4. Soportes y notificación</div>
                    <div class="section-kicker">Adjunte evidencias del caso y notifique al aprendiz.</div>
                </div>
            </div>
            <div class="professional-card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tipo de soporte</label>
                        <select name="tipo_soporte_general">
                            <option>Soporte disciplinario</option>
                            <option>Acta de compromiso</option>
                            <option>Control de asistencia</option>
                            <option>Acta</option>
                            <option>Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Archivo soporte</label>
                        <input type="file" name="soporte_general" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx">
                    </div>
                    <div class="form-group full">
                        <label>Descripción del soporte</label>
                        <textarea name="descripcion_soporte_general"></textarea>
                    </div>
                    <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="registrar_notificacion" value="1" style="width:auto" checked>
                        <label style="text-transform:none">Registrar notificación al aprendiz</label>
                    </div>
                    <div class="form-group">
                        <label>Correo del aprendiz</label>
                        <input type="email" name="correo_destino" id="correo_destino">
                    </div>
                    <div class="form-group">
                        <label>Asunto</label>
                        <input type="text" name="asunto_notificacion" value="Notificación de caso disciplinario">
                    </div>
                    <div class="form-group full">
                        <label>Mensaje de notificación</label>
                        <textarea name="mensaje_notificacion" placeholder="Indique la falta, la medida adoptada y los compromisos adquiridos."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div id="disc-guardar" style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:28px">
            <a href="expediente.php" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary" style="background:var(--naranja);border-color:var(--naranja)">
                Guardar caso disciplinario
            </button>
        </div>
    </form>
</div>

<script>
function llenarCorreo() {
    const opt = document.getElementById('aprendiz_id').selectedOptions[0];
    document.getElementById('correo_destino').value = opt ? (opt.dataset.email || '') : '';
}
function toggleMedida() {
    const hubo = document.getElementById('hubo_accion').value === 'Si';
    document.querySelectorAll('.medida-si').forEach(el => el.style.display = hubo ? '' : 'none');
    document.querySelectorAll('.medida-no').forEach(el => el.style.display = hubo ? 'none' : '');
}
function initPad(canvasId, hiddenId, placeholderId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let drawing = false;
    ctx.strokeStyle = '#1a2e22'; ctx.lineWidth = 2.5; ctx.lineCap = 'round';
    function pos(e) {
        const r = canvas.getBoundingClientRect();
        const src = e.touches ? e.touches[0] : e;
        return {x:(src.clientX-r.left)*(canvas.width/r.width), y:(src.clientY-r.top)*(canvas.height/r.height)};
    }
    function start(e){ e.preventDefault(); drawing=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); document.getElementById(placeholderId).style.display='none'; }
    function move(e){ if(!drawing)return; e.preventDefault(); const p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); }
    function end(){ if(drawing){ drawing=false; document.getElementById(hiddenId).value=canvas.toDataURL(); } }
    canvas.addEventListener('mousedown',start); canvas.addEventListener('mousemove',move);
    canvas.addEventListener('mouseup',end); canvas.addEventListener('mouseleave',end);
    canvas.addEventListener('touchstart',start,{passive:false}); canvas.addEventListener('touchmove',move,{passive:false}); canvas.addEventListener('touchend',end);
}
function clearPad(canvasId, hiddenId, placeholderId) {
    const canvas = document.getElementById(canvasId);
    canvas.getContext('2d').clearRect(0,0,canvas.width,canvas.height);
    document.getElementById(hiddenId).value = '';
    document.getElementById(placeholderId).style.display = 'flex';
}
function capturarFirmasDisc() {
    [['padInstructor','firma_instructor'],['padAprendiz','firma_aprendiz']].forEach(([cId,hId]) => {
        const c = document.getElementById(cId), h = document.getElementById(hId);
        if (c && h) { const b = document.createElement('canvas'); b.width=c.width; b.height=c.height; if (c.toDataURL()!==b.toDataURL()) h.value=c.toDataURL(); }
    });
}
document.addEventListener('DOMContentLoaded', () => {
    toggleMedida();
    initPad('padInstructor','firma_instructor','phInstructor');
    initPad('padAprendiz','firma_aprendiz','phAprendiz');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
