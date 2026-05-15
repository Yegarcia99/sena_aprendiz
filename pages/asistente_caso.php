<?php
// pages/asistente_caso.php - Flujo guiado para registrar un caso completo
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
requireLogin();

$pageTitle = 'Asistente de Caso';
$db = getDB();
ensureExpedienteSchema($db);

$msg = $err = '';
$user = getCurrentUser();

function postBool(string $key): int {
    return isset($_POST[$key]) ? 1 : 0;
}

function firstUploadedFile(string $field): ?array {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    return $_FILES[$field];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $db->beginTransaction();

        $aprendizId = (int)($_POST['aprendiz_id'] ?? 0);
        $competenciaId = (int)($_POST['competencia_id'] ?? 0);
        $resultadoId = (int)($_POST['resultado_id'] ?? 0) ?: null;
        $instructorId = (int)($_POST['instructor_id'] ?? 0);
        $tipoCaso = $_POST['tipo_caso'] ?? 'Academico';
        $momentoProceso = $_POST['momento_proceso'] ?? 'Durante el resultado';
        $estadoPendiente = $_POST['estado_pendiente'] ?? 'Pendiente';
        $trimestre = (int)($_POST['trimestre_ocurrencia'] ?? 1);
        $motivo = trim($_POST['motivo'] ?? '');
        $observacionesPendiente = trim($_POST['observaciones_pendiente'] ?? '');

        if (!$aprendizId || !$competenciaId || !$instructorId || !$motivo) {
            throw new RuntimeException('Seleccione aprendiz, competencia, instructor y describa el motivo del caso.');
        }

        $stmt = $db->prepare("
            INSERT INTO pendientes_aprendices
            (aprendiz_id, competencia_id, resultado_id, instructor_id, trimestre_ocurrencia, fecha_registro, tipo_caso, motivo, debe_repetir_competencia, estado, observaciones)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $aprendizId,
            $competenciaId,
            $resultadoId,
            $instructorId,
            $trimestre,
            $_POST['fecha_registro'] ?? date('Y-m-d'),
            $tipoCaso,
            $motivo,
            postBool('debe_repetir'),
            $estadoPendiente,
            trim($observacionesPendiente . "\nMomento del proceso: " . $momentoProceso),
        ]);
        $pendienteId = (int)$db->lastInsertId();

        $huboAccion = $_POST['hubo_accion'] ?? 'Si';
        $accionResultado = $_POST['resultado_accion'] ?? 'En proceso';
        $accionTipo = $huboAccion === 'Si' ? ($_POST['tipo_accion'] ?? 'Refuerzo presencial') : 'Sin accion remedial - justificacion';
        $accionDescripcion = trim($_POST['descripcion_accion'] ?? '');
        $justificacionSinAccion = trim($_POST['justificacion_sin_accion'] ?? '');

        if ($huboAccion === 'Si' && $accionDescripcion === '') {
            throw new RuntimeException('Describa la accion remedial realizada.');
        }
        if ($huboAccion !== 'Si' && $justificacionSinAccion === '') {
            throw new RuntimeException('Explique por que no aplica accion remedial.');
        }

        $stmt = $db->prepare("
            INSERT INTO acciones_remediales
            (pendiente_id, instructor_id, fecha_accion, tipo_accion, descripcion, resultado, novedad_aprobacion, observaciones, firma_instructor, firma_aprendiz)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $pendienteId,
            $instructorId,
            $_POST['fecha_accion'] ?? date('Y-m-d'),
            $accionTipo,
            $huboAccion === 'Si' ? $accionDescripcion : $justificacionSinAccion,
            $accionResultado,
            postBool('novedad_aprobacion'),
            trim($_POST['observaciones_accion'] ?? ''),
            $_POST['firma_accion_instructor'] ?: null,
            $_POST['firma_accion_aprendiz'] ?: null,
        ]);
        $accionId = (int)$db->lastInsertId();

        if ($file = firstUploadedFile('soporte_accion')) {
            guardarSoporteExpediente($db, $file, [
                'aprendiz_id' => $aprendizId,
                'pendiente_id' => $pendienteId,
                'accion_id' => $accionId,
                'tipo_soporte' => $huboAccion === 'Si' ? 'Soporte de accion remedial' : 'Justificacion sin accion remedial',
                'descripcion' => trim($_POST['observaciones_accion'] ?? ''),
                'subido_por' => $user['id'] ?? null,
            ]);
        }

        $crearPlan = isset($_POST['crear_plan']);
        if ($crearPlan) {
            $descripcionPlan = trim($_POST['descripcion_plan'] ?? '');
            if ($descripcionPlan === '') {
                throw new RuntimeException('Para crear instancia debe describir el plan de mejoramiento.');
            }
            $stmt = $db->prepare("
                INSERT INTO planes_mejoramiento
                (pendiente_id, aprendiz_id, instancia, fecha_concertacion, evidencia_conocimiento, evidencia_producto, evidencia_desempeno, descripcion_plan, compromisos, estado, instructor_id, coordinador_id, firma_instructor, firma_coordinador, firma_aprendiz)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $pendienteId,
                $aprendizId,
                $_POST['instancia'] ?? 'Primera instancia',
                $_POST['fecha_concertacion'] ?? date('Y-m-d'),
                postBool('evidencia_conocimiento'),
                postBool('evidencia_producto'),
                postBool('evidencia_desempeno'),
                $descripcionPlan,
                trim($_POST['compromisos_plan'] ?? ''),
                $_POST['estado_plan'] ?? 'Abierto',
                $instructorId,
                (int)($_POST['coordinador_id'] ?? 0) ?: null,
                $_POST['firma_plan_instructor'] ?: null,
                $_POST['firma_plan_coordinador'] ?: null,
                $_POST['firma_plan_aprendiz'] ?: null,
            ]);
            $planId = (int)$db->lastInsertId();
            $db->prepare("UPDATE pendientes_aprendices SET estado=? WHERE id=?")->execute([$_POST['instancia'] ?? 'Primera instancia', $pendienteId]);

            if ($file = firstUploadedFile('soporte_plan')) {
                guardarSoporteExpediente($db, $file, [
                    'aprendiz_id' => $aprendizId,
                    'pendiente_id' => $pendienteId,
                    'plan_id' => $planId,
                    'tipo_soporte' => 'Acta de plan de mejoramiento',
                    'descripcion' => 'Acta o evidencia de concertacion del plan',
                    'subido_por' => $user['id'] ?? null,
                ]);
            }
        } elseif ($accionResultado === 'Aprobado') {
            $db->prepare("UPDATE pendientes_aprendices SET estado='Superado' WHERE id=?")->execute([$pendienteId]);
        } elseif ($accionResultado === 'No aprobado') {
            $db->prepare("UPDATE pendientes_aprendices SET estado='No aprobado' WHERE id=?")->execute([$pendienteId]);
        } else {
            $db->prepare("UPDATE pendientes_aprendices SET estado='En proceso' WHERE id=?")->execute([$pendienteId]);
        }

        if ($file = firstUploadedFile('soporte_general')) {
            guardarSoporteExpediente($db, $file, [
                'aprendiz_id' => $aprendizId,
                'pendiente_id' => $pendienteId,
                'tipo_soporte' => $_POST['tipo_soporte_general'] ?? 'Soporte general',
                'descripcion' => trim($_POST['descripcion_soporte_general'] ?? ''),
                'subido_por' => $user['id'] ?? null,
            ]);
        }

        if (isset($_POST['registrar_notificacion'])) {
            $correo = trim($_POST['correo_destino'] ?? '');
            $asunto = trim($_POST['asunto_notificacion'] ?? '');
            $mensajeNot = trim($_POST['mensaje_notificacion'] ?? '');
            if ($correo && $asunto && $mensajeNot) {
                $stmt = $db->prepare("
                    INSERT INTO notificaciones
                    (aprendiz_id, pendiente_id, referencia_tipo, referencia_id, correo_destino, asunto, mensaje, estado_envio, enviado_por)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([$aprendizId, $pendienteId, 'Asistente de caso', $pendienteId, $correo, $asunto, $mensajeNot, 'Registrada', $user['id'] ?? null]);
            }
        }

        $db->commit();
        $msg = 'Caso registrado. Revise el expediente para ver la trazabilidad completa.';
        $_GET['aprendiz_id'] = $aprendizId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
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
$instructores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM instructores WHERE activo=1 ORDER BY nombres")->fetchAll();
$competencias = $db->query("SELECT c.id, c.nombre, c.trimestre, p.nombre AS programa FROM competencias c JOIN programas p ON p.id=c.programa_id WHERE c.activa=1 ORDER BY c.nombre")->fetchAll();
$coordinadores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM usuarios WHERE rol IN ('Administrador','Coordinador') AND activo=1 ORDER BY nombres")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Asistente de Caso</div>
        <div class="page-subtitle">Registro guiado de dificultad, accion remedial, evidencias, instancia y notificacion</div>
    </div>
    <a href="expediente.php" class="btn btn-secondary">Ver expedientes</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div class="table-card" style="margin-bottom:18px">
    <div class="table-card-header">
        <div class="table-card-title">Ruta del caso</div>
    </div>
    <div style="padding:18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
        <div class="alert alert-info" style="margin:0">1. Caso o pendiente</div>
        <div class="alert alert-info" style="margin:0">2. Accion o justificacion</div>
        <div class="alert alert-warning" style="margin:0">3. Evidencias y firmas</div>
        <div class="alert alert-success" style="margin:0">4. Primera o segunda instancia</div>
        <div class="alert alert-info" style="margin:0">5. Expediente listo para comite</div>
    </div>
</div>

<div class="case-shell">
    <div class="case-nav">
        <a href="#datos">1. Datos del caso</a>
        <a href="#accion">2. Accion o justificacion</a>
        <a href="#instancia">3. Instancia y acta</a>
        <a href="#notificacion">4. Soportes y notificacion</a>
        <a href="#guardar">5. Guardar</a>
    </div>

<form method="POST" enctype="multipart/form-data" onsubmit="capturarFirmasAsistente()">
    <div class="table-card case-section" id="datos" style="margin-bottom:18px">
        <div class="table-card-header">
            <div>
                <div class="table-card-title">1. Datos del caso</div>
                <div class="section-kicker">Identifique al aprendiz, el tipo de situacion y el momento del proceso.</div>
            </div>
        </div>
        <div class="professional-card-body">
            <div class="form-grid">
                <div class="form-group full">
                    <label>Aprendiz *</label>
                    <select name="aprendiz_id" id="aprendiz_id" required onchange="llenarCorreoAprendiz()">
                        <option value="">-- Seleccionar aprendiz --</option>
                        <?php foreach ($aprendices as $ap): ?>
                        <option value="<?= $ap['id'] ?>" data-email="<?= sanitize($ap['email'] ?? '') ?>"><?= sanitize($ap['nombre']) ?> - <?= sanitize($ap['documento']) ?> / Ficha <?= sanitize($ap['numero_ficha']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tipo de caso</label>
                    <select name="tipo_caso">
                        <option>Academico</option><option>Inasistencia</option><option>Disciplinario</option><option>Desercion</option><option>Otro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Momento del proceso</label>
                    <select name="momento_proceso" id="momento_proceso" onchange="sugerirPlan()">
                        <option>Durante el resultado</option>
                        <option>Resultado finalizado</option>
                        <option>Caso grave o excepcional</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Estado inicial</label>
                    <select name="estado_pendiente">
                        <option>Pendiente</option><option>En proceso</option><option>No aprobado</option><option>Listo para comite</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Instructor responsable *</label>
                    <select name="instructor_id" required><option value="">-- Seleccionar --</option><?php foreach ($instructores as $i): ?><option value="<?= $i['id'] ?>"><?= sanitize($i['nombre']) ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group">
                    <label>Competencia *</label>
                    <select name="competencia_id" id="competencia_id" required onchange="cargarResultadosAsistente(this.value)">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($competencias as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['nombre']) ?> - <?= sanitize($c['programa']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Resultado</label>
                    <select name="resultado_id" id="resultado_id"><option value="">-- Primero seleccione competencia --</option></select>
                </div>
                <div class="form-group">
                    <label>Trimestre</label>
                    <select name="trimestre_ocurrencia"><?php for ($t=1;$t<=8;$t++): ?><option value="<?= $t ?>"><?= $t ?> Trimestre</option><?php endfor; ?></select>
                </div>
                <div class="form-group">
                    <label>Fecha registro</label>
                    <input type="date" name="fecha_registro" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group full">
                    <label>Motivo del caso *</label>
                    <textarea name="motivo" required placeholder="Explique que ocurrio: evidencia perdida, inasistencias, incumplimiento, caso disciplinario, etc."></textarea>
                </div>
                <div class="form-group full">
                    <label>Observaciones</label>
                    <textarea name="observaciones_pendiente" placeholder="Contexto adicional para coordinacion o comite."></textarea>
                </div>
                <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="debe_repetir" id="debe_repetir" value="1" style="width:auto">
                    <label for="debe_repetir" style="text-transform:none">Debe repetir competencia</label>
                </div>
            </div>
        </div>
    </div>

    <div class="table-card case-section" id="accion" style="margin-bottom:18px">
        <div class="table-card-header">
            <div>
                <div class="table-card-title">2. Accion remedial o justificacion</div>
                <div class="section-kicker">Durante el resultado se documentan las estrategias metodologicas; si no aplican, debe quedar justificacion.</div>
            </div>
        </div>
        <div class="professional-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>Hubo accion remedial?</label>
                    <select name="hubo_accion" id="hubo_accion" onchange="toggleAccion()">
                        <option>Si</option><option>No</option>
                    </select>
                </div>
                <div class="form-group accion-si">
                    <label>Tipo de accion</label>
                    <select name="tipo_accion"><option>Refuerzo presencial</option><option>Tutoria individual</option><option>Taller compensatorio</option><option>Trabajo practico</option><option>Evaluacion oral</option><option>Otro</option></select>
                </div>
                <div class="form-group">
                    <label>Fecha accion / registro</label>
                    <input type="date" name="fecha_accion" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Resultado</label>
                    <select name="resultado_accion" id="resultado_accion" onchange="sugerirPlan()"><option>En proceso</option><option>Aprobado</option><option>No aprobado</option></select>
                </div>
                <div class="form-group full accion-si">
                    <label>Descripcion de la accion remedial</label>
                    <textarea name="descripcion_accion" placeholder="Detalle la estrategia metodologica usada y la oportunidad dada al aprendiz."></textarea>
                </div>
                <div class="form-group full accion-no" style="display:none">
                    <label>Justificacion por no realizar accion remedial</label>
                    <textarea name="justificacion_sin_accion" placeholder="Ejemplo: el aprendiz no asistio a clase; se adjunta control de inasistencia."></textarea>
                </div>
                <div class="form-group full">
                    <label>Observaciones de la accion</label>
                    <textarea name="observaciones_accion" placeholder="Compromisos, fecha de citacion, soporte de inasistencia o detalle para coordinacion."></textarea>
                </div>
                <div class="form-group full">
                    <label>Soporte de accion o justificacion</label>
                    <input type="file" name="soporte_accion" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx">
                </div>
                <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="novedad_aprobacion" id="novedad_aprobacion" value="1" style="width:auto">
                    <label for="novedad_aprobacion" style="text-transform:none">Instructor registro novedad de aprobacion</label>
                </div>
            </div>

            <div style="margin-top:18px;border-top:1px solid var(--gris-border);padding-top:16px">
                <div class="table-card-title" style="margin-bottom:12px">Firmas de accion / justificacion</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
                    <div>
                        <label>Firma instructor</label>
                        <input type="hidden" name="firma_accion_instructor" id="firma_accion_instructor">
                        <div class="signature-pad-wrap"><canvas id="padAccionInstructor" width="500" height="180"></canvas><div class="signature-placeholder" id="phAccionInstructor">Firmar aqui</div></div>
                        <div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padAccionInstructor','firma_accion_instructor','phAccionInstructor')">Limpiar</button></div>
                    </div>
                    <div>
                        <label>Firma aprendiz</label>
                        <input type="hidden" name="firma_accion_aprendiz" id="firma_accion_aprendiz">
                        <div class="signature-pad-wrap"><canvas id="padAccionAprendiz" width="500" height="180"></canvas><div class="signature-placeholder" id="phAccionAprendiz">Firmar aqui</div></div>
                        <div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padAccionAprendiz','firma_accion_aprendiz','phAccionAprendiz')">Limpiar</button></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="table-card case-section" id="instancia" style="margin-bottom:18px">
        <div class="table-card-header">
            <div>
                <div class="table-card-title">3. Primera o segunda instancia</div>
                <div class="section-kicker">Cuando el resultado finalizo y queda no aprobado, registre el plan de mejoramiento y su acta.</div>
            </div>
        </div>
        <div class="professional-card-body">
            <div class="alert alert-warning">Use esta seccion cuando el resultado ya finalizo y debe quedar acta de plan de mejoramiento. Maximo dos instancias antes de comite, salvo caso grave.</div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
                <input type="checkbox" name="crear_plan" id="crear_plan" value="1" style="width:auto" onchange="togglePlan()">
                <label for="crear_plan" style="text-transform:none">Crear plan de mejoramiento en este registro</label>
            </div>
            <div id="planBox" style="display:none">
                <div class="form-grid">
                    <div class="form-group"><label>Instancia</label><select name="instancia"><option>Primera instancia</option><option>Segunda instancia</option></select></div>
                    <div class="form-group"><label>Fecha concertacion</label><input type="date" name="fecha_concertacion" value="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group"><label>Coordinador</label><select name="coordinador_id"><option value="">-- Seleccionar --</option><?php foreach ($coordinadores as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['nombre']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Estado plan</label><select name="estado_plan"><option>Abierto</option><option>Cumplido</option><option>No cumplido</option><option>Cerrado</option></select></div>
                    <div class="form-group full" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap">
                        <label style="text-transform:none"><input type="checkbox" name="evidencia_conocimiento" style="width:auto"> Evidencia de conocimiento</label>
                        <label style="text-transform:none"><input type="checkbox" name="evidencia_producto" style="width:auto"> Evidencia de producto</label>
                        <label style="text-transform:none"><input type="checkbox" name="evidencia_desempeno" style="width:auto"> Evidencia de desempeno</label>
                    </div>
                    <div class="form-group full"><label>Plan concertado</label><textarea name="descripcion_plan" placeholder="Orientaciones, estrategias pedagogicas, evidencias requeridas y fechas."></textarea></div>
                    <div class="form-group full"><label>Compromisos</label><textarea name="compromisos_plan" placeholder="Compromisos del aprendiz, instructor y coordinacion."></textarea></div>
                    <div class="form-group full"><label>Acta o soporte del plan</label><input type="file" name="soporte_plan" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
                </div>
                <div style="margin-top:18px;border-top:1px solid var(--gris-border);padding-top:16px">
                    <div class="table-card-title" style="margin-bottom:12px">Firmas del plan</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
                        <div><label>Instructor</label><input type="hidden" name="firma_plan_instructor" id="firma_plan_instructor"><div class="signature-pad-wrap"><canvas id="padPlanInstructor" width="500" height="180"></canvas><div class="signature-placeholder" id="phPlanInstructor">Firmar aqui</div></div><div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padPlanInstructor','firma_plan_instructor','phPlanInstructor')">Limpiar</button></div></div>
                        <div><label>Coordinador</label><input type="hidden" name="firma_plan_coordinador" id="firma_plan_coordinador"><div class="signature-pad-wrap"><canvas id="padPlanCoordinador" width="500" height="180"></canvas><div class="signature-placeholder" id="phPlanCoordinador">Firmar aqui</div></div><div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padPlanCoordinador','firma_plan_coordinador','phPlanCoordinador')">Limpiar</button></div></div>
                        <div><label>Aprendiz</label><input type="hidden" name="firma_plan_aprendiz" id="firma_plan_aprendiz"><div class="signature-pad-wrap"><canvas id="padPlanAprendiz" width="500" height="180"></canvas><div class="signature-placeholder" id="phPlanAprendiz">Firmar aqui</div></div><div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padPlanAprendiz','firma_plan_aprendiz','phPlanAprendiz')">Limpiar</button></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="table-card case-section" id="notificacion" style="margin-bottom:18px">
        <div class="table-card-header">
            <div>
                <div class="table-card-title">4. Soporte adicional y notificacion</div>
                <div class="section-kicker">Adjunte evidencias complementarias y deje constancia de que el aprendiz fue informado.</div>
            </div>
        </div>
        <div class="professional-card-body">
            <div class="form-grid">
                <div class="form-group"><label>Tipo de soporte adicional</label><select name="tipo_soporte_general"><option>Control de inasistencia</option><option>Evidencia academica</option><option>Soporte disciplinario</option><option>Acta</option><option>Otro</option></select></div>
                <div class="form-group"><label>Archivo adicional</label><input type="file" name="soporte_general" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx"></div>
                <div class="form-group full"><label>Descripcion soporte adicional</label><textarea name="descripcion_soporte_general"></textarea></div>
                <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="registrar_notificacion" id="registrar_notificacion" value="1" style="width:auto" checked>
                    <label for="registrar_notificacion" style="text-transform:none">Registrar notificacion al aprendiz como evidencia</label>
                </div>
                <div class="form-group"><label>Correo aprendiz</label><input type="email" name="correo_destino" id="correo_destino"></div>
                <div class="form-group"><label>Asunto</label><input type="text" name="asunto_notificacion" value="Concertacion de seguimiento academico"></div>
                <div class="form-group full"><label>Mensaje notificacion</label><textarea name="mensaje_notificacion" placeholder="Indique fecha, hora, evidencia, lugar o compromiso informado al aprendiz."></textarea></div>
            </div>
        </div>
    </div>

    <div id="guardar" style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:28px">
        <a href="expediente.php" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar caso completo</button>
    </div>
</form>
</div>

<script>
function cargarResultadosAsistente(competenciaId) {
    const sel = document.getElementById('resultado_id');
    sel.innerHTML = '<option value="">Cargando...</option>';
    if (!competenciaId) {
        sel.innerHTML = '<option value="">-- Primero seleccione competencia --</option>';
        return;
    }
    fetch('<?= BASE_URL ?>/ajax/resultados.php?competencia_id=' + competenciaId)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">-- Sin resultado especifico --</option>';
            data.forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = r.nombre;
                sel.appendChild(opt);
            });
        })
        .catch(() => sel.innerHTML = '<option value="">No se pudieron cargar resultados</option>');
}
function llenarCorreoAprendiz() {
    const opt = document.getElementById('aprendiz_id').selectedOptions[0];
    document.getElementById('correo_destino').value = opt ? (opt.dataset.email || '') : '';
}
function toggleAccion() {
    const hubo = document.getElementById('hubo_accion').value === 'Si';
    document.querySelectorAll('.accion-si').forEach(el => el.style.display = hubo ? '' : 'none');
    document.querySelectorAll('.accion-no').forEach(el => el.style.display = hubo ? 'none' : '');
}
function togglePlan() {
    document.getElementById('planBox').style.display = document.getElementById('crear_plan').checked ? '' : 'none';
}
function sugerirPlan() {
    const momento = document.getElementById('momento_proceso').value;
    const resultado = document.getElementById('resultado_accion').value;
    if (momento !== 'Durante el resultado' || resultado === 'No aprobado') {
        document.getElementById('crear_plan').checked = true;
        togglePlan();
    }
}
function initPadAsistente(canvasId, hiddenId, placeholderId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let drawing = false;
    ctx.strokeStyle = '#1a2e22';
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    function pos(e) {
        const r = canvas.getBoundingClientRect();
        const src = e.touches ? e.touches[0] : e;
        return {x:(src.clientX-r.left)*(canvas.width/r.width), y:(src.clientY-r.top)*(canvas.height/r.height)};
    }
    function start(e){ e.preventDefault(); drawing=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); document.getElementById(placeholderId).style.display='none'; }
    function move(e){ if(!drawing)return; e.preventDefault(); const p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); }
    function end(){ if(drawing){ drawing=false; document.getElementById(hiddenId).value=canvas.toDataURL(); } }
    canvas.addEventListener('mousedown',start); canvas.addEventListener('mousemove',move); canvas.addEventListener('mouseup',end); canvas.addEventListener('mouseleave',end);
    canvas.addEventListener('touchstart',start,{passive:false}); canvas.addEventListener('touchmove',move,{passive:false}); canvas.addEventListener('touchend',end);
}
function clearPadAsistente(canvasId, hiddenId, placeholderId) {
    const canvas = document.getElementById(canvasId);
    canvas.getContext('2d').clearRect(0,0,canvas.width,canvas.height);
    document.getElementById(hiddenId).value = '';
    document.getElementById(placeholderId).style.display = 'flex';
}
function isBlank(canvas) {
    const blank = document.createElement('canvas');
    blank.width = canvas.width;
    blank.height = canvas.height;
    return canvas.toDataURL() === blank.toDataURL();
}
function capturarFirmasAsistente() {
    [
        ['padAccionInstructor','firma_accion_instructor'],
        ['padAccionAprendiz','firma_accion_aprendiz'],
        ['padPlanInstructor','firma_plan_instructor'],
        ['padPlanCoordinador','firma_plan_coordinador'],
        ['padPlanAprendiz','firma_plan_aprendiz']
    ].forEach(([canvasId, hiddenId]) => {
        const canvas = document.getElementById(canvasId);
        if (canvas && !isBlank(canvas)) document.getElementById(hiddenId).value = canvas.toDataURL();
    });
}
document.addEventListener('DOMContentLoaded', () => {
    toggleAccion();
    togglePlan();
    ['AccionInstructor','AccionAprendiz','PlanInstructor','PlanCoordinador','PlanAprendiz'].forEach(name => {
        initPadAsistente('pad' + name, 'firma_' + name.replace('Accion','accion_').replace('Plan','plan_').replace('Instructor','instructor').replace('Aprendiz','aprendiz').replace('Coordinador','coordinador'), 'ph' + name);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
