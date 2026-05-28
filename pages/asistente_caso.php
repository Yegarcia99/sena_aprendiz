<?php
// pages/asistente_caso.php - Flujo guiado para registrar un caso completo
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
require_once __DIR__ . '/../includes/academico_flujo.php';
requireLogin();
denyIfAprendiz(); // Aprendiz no tiene acceso a esta página
denyIfInstructorOrAprendiz();

$pageTitle = 'Registro Guiado Avanzado';
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

        $aprendizId    = (int)($_POST['aprendiz_id'] ?? 0);
        $competenciaId = (int)($_POST['competencia_id'] ?? 0);
        $resultadoId   = (int)($_POST['resultado_id'] ?? 0) ?: null;
        $instructorId  = (int)($_POST['instructor_id'] ?? 0);
        $tipoCaso      = $_POST['tipo_caso'] ?? 'Academico';
        $momentoProceso     = $_POST['momento_proceso'] ?? 'Durante el resultado';
        $estadoPendiente    = $_POST['estado_pendiente'] ?? 'Pendiente';
        $trimestre          = (int)($_POST['trimestre_ocurrencia'] ?? 1);
        $motivo             = trim($_POST['motivo'] ?? '');
        $observacionesPendiente = trim($_POST['observaciones_pendiente'] ?? '');

        if (!$aprendizId || !$instructorId || !$motivo) {
            throw new RuntimeException('Seleccione aprendiz, instructor y describa el motivo del caso.');
        }

        // Para casos académicos se requiere competencia
        $esDisciplinario = in_array($tipoCaso, ['Disciplinario', 'Convivencia', 'Falta disciplinaria']);
        if (!$esDisciplinario && !$competenciaId) {
            throw new RuntimeException('Para casos académicos debe seleccionar una competencia.');
        }

        if (!$esDisciplinario) {
            $estadoPendiente = 'Pendiente';
        }

        $gestorId = null;
        if (!$esDisciplinario) {
            $gestorStmt = $db->prepare("SELECT f.gestor_id FROM aprendices a JOIN fichas f ON f.id = a.ficha_id WHERE a.id = ?");
            $gestorStmt->execute([$aprendizId]);
            $gestorId = (int)($gestorStmt->fetchColumn() ?: 0) ?: null;
        }

        $stmt = $db->prepare("
            INSERT INTO pendientes_aprendices
            (aprendiz_id, competencia_id, resultado_id, instructor_id, gestor_id, trimestre_ocurrencia, fecha_registro, tipo_caso, motivo, debe_repetir_competencia, estado, estado_flujo, instancia_actual, observaciones)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $aprendizId,
            $esDisciplinario ? null : $competenciaId,
            $esDisciplinario ? null : $resultadoId,
            $instructorId,
            $gestorId,
            $trimestre,
            $_POST['fecha_registro'] ?? date('Y-m-d'),
            $tipoCaso,
            $motivo,
            $esDisciplinario ? 0 : postBool('debe_repetir'),
            $estadoPendiente,
            $esDisciplinario ? 'Reportado disciplinario' : 'Reportado',
            0,
            trim($observacionesPendiente . "\nMomento del proceso: " . $momentoProceso),
        ]);
        $pendienteId = (int)$db->lastInsertId();
        if (!$esDisciplinario) {
            registrarEventoAcademico($db, $pendienteId, $aprendizId, 'Registro guiado avanzado', 'Reportado', $motivo);
            notificarReporteAcademico($db, $pendienteId);
        }

        $huboAccion      = $_POST['hubo_accion'] ?? 'Si';
        $accionResultado = $_POST['resultado_accion'] ?? 'En proceso';
        $accionTipo      = $huboAccion === 'Si' ? ($_POST['tipo_accion'] ?? 'Refuerzo presencial') : 'Sin accion remedial - justificacion';
        $accionDescripcion       = trim($_POST['descripcion_accion'] ?? '');
        $justificacionSinAccion  = trim($_POST['justificacion_sin_accion'] ?? '');
        $fechaLimiteAccion       = ($_POST['fecha_limite_accion'] ?? '') ?: null;

        if ($huboAccion === 'Si' && $accionDescripcion === '') {
            throw new RuntimeException('Describa la accion realizada.');
        }
        if ($huboAccion !== 'Si' && $justificacionSinAccion === '') {
            throw new RuntimeException('Explique por que no aplica accion.');
        }

        $stmt = $db->prepare("
            INSERT INTO acciones_remediales
            (pendiente_id, instructor_id, fecha_accion, fecha_limite, tipo_accion, descripcion, resultado, novedad_aprobacion, observaciones, firma_instructor, firma_aprendiz)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $pendienteId,
            $instructorId,
            $_POST['fecha_accion'] ?? date('Y-m-d'),
            $fechaLimiteAccion,
            $accionTipo,
            $huboAccion === 'Si' ? $accionDescripcion : $justificacionSinAccion,
            $accionResultado,
            postBool('novedad_aprobacion'),
            trim($_POST['observaciones_accion'] ?? ''),
            $_POST['firma_accion_instructor'] ?: null,
            $_POST['firma_accion_aprendiz'] ?: null,
        ]);
        $accionId = (int)$db->lastInsertId();
        if (!$esDisciplinario) {
            registrarEventoAcademico($db, $pendienteId, $aprendizId, 'Accion remedial', 'Accion remedial asignada', $huboAccion === 'Si' ? $accionDescripcion : $justificacionSinAccion, 'Reportado', 0, $fechaLimiteAccion, $accionId);
            notificarAccionRemedial($db, $pendienteId, $accionId, $fechaLimiteAccion);
        }

        if ($file = firstUploadedFile('soporte_accion')) {
            guardarSoporteExpediente($db, $file, [
                'aprendiz_id'  => $aprendizId,
                'pendiente_id' => $pendienteId,
                'accion_id'    => $accionId,
                'tipo_soporte' => $huboAccion === 'Si' ? 'Soporte de accion remedial' : 'Justificacion sin accion remedial',
                'descripcion'  => trim($_POST['observaciones_accion'] ?? ''),
                'subido_por'   => $user['id'] ?? null,
            ]);
        }

        // Plan de mejoramiento (solo académico)
        $crearPlan = false;
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
                    'aprendiz_id'  => $aprendizId,
                    'pendiente_id' => $pendienteId,
                    'plan_id'      => $planId,
                    'tipo_soporte' => 'Acta de plan de mejoramiento',
                    'descripcion'  => 'Acta o evidencia de concertacion del plan',
                    'subido_por'   => $user['id'] ?? null,
                ]);
            }
        } elseif ($accionResultado === 'Aprobado') {
            $db->prepare("UPDATE pendientes_aprendices SET estado='Superado', estado_flujo='Superado' WHERE id=?")->execute([$pendienteId]);
        } elseif ($accionResultado === 'No aprobado') {
            $db->prepare("UPDATE pendientes_aprendices SET estado='En proceso', estado_flujo='Accion remedial no aprobada', fecha_limite_actual=? WHERE id=?")->execute([$fechaLimiteAccion, $pendienteId]);
        } else {
            $db->prepare("UPDATE pendientes_aprendices SET estado='En proceso', estado_flujo='Accion remedial asignada', fecha_limite_actual=? WHERE id=?")->execute([$fechaLimiteAccion, $pendienteId]);
        }

        if ($file = firstUploadedFile('soporte_general')) {
            guardarSoporteExpediente($db, $file, [
                'aprendiz_id'  => $aprendizId,
                'pendiente_id' => $pendienteId,
                'tipo_soporte' => $_POST['tipo_soporte_general'] ?? 'Soporte general',
                'descripcion'  => trim($_POST['descripcion_soporte_general'] ?? ''),
                'subido_por'   => $user['id'] ?? null,
            ]);
        }

        if (isset($_POST['registrar_notificacion'])) {
            $correo    = trim($_POST['correo_destino'] ?? '');
            $asunto    = trim($_POST['asunto_notificacion'] ?? '');
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
        $msg = 'Caso registrado correctamente. Revise el expediente para ver la trazabilidad completa.';
        $_GET['aprendiz_id'] = $aprendizId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $err = $e->getMessage();
    }
}

$aprendices   = $db->query("SELECT a.id, CONCAT(a.apellidos, ', ', a.nombres) AS nombre, a.documento, a.email, f.numero_ficha FROM aprendices a JOIN fichas f ON f.id=a.ficha_id WHERE a.estado='Activo' ORDER BY a.apellidos, a.nombres")->fetchAll();
$instructores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM instructores WHERE activo=1 ORDER BY nombres")->fetchAll();
$competencias = $db->query("SELECT c.id, c.nombre, c.trimestre, p.nombre AS programa FROM competencias c JOIN programas p ON p.id=c.programa_id WHERE c.activa=1 ORDER BY c.nombre")->fetchAll();
$coordinadores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM usuarios WHERE rol IN ('Administrador','Coordinador') AND activo=1 ORDER BY nombres")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Registro Guiado Avanzado</div>
        <div class="page-subtitle">Herramienta auxiliar para gestores, coordinacion y administracion</div>
    </div>
    <a href="expediente.php" class="btn btn-secondary">Ver expedientes</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<!-- ── Selector de tipo de caso ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:22px">
    <div id="tab-academico" onclick="cambiarTab('academico')" style="cursor:pointer;border-radius:var(--radius);padding:18px 20px;background:#fff;box-shadow:var(--shadow);border-top:3px solid var(--verde);transition:box-shadow .15s">
        <div style="display:flex;align-items:center;gap:12px">
            <div style="width:40px;height:40px;border-radius:10px;background:var(--verde-soft);display:flex;align-items:center;justify-content:center;font-size:20px">📚</div>
            <div>
                <div style="font-size:14px;font-weight:700;color:var(--ink)">Caso Académico</div>
                <div style="font-size:11.5px;color:var(--muted);margin-top:2px">Inasistencias, evidencias, deserción, plan de mejoramiento</div>
            </div>
        </div>
    </div>
    <div id="tab-disciplinario" onclick="cambiarTab('disciplinario')" style="cursor:pointer;border-radius:var(--radius);padding:18px 20px;background:#fff;box-shadow:var(--shadow);border-top:3px solid #dde8e1;transition:box-shadow .15s">
        <div style="display:flex;align-items:center;gap:12px">
            <div style="width:40px;height:40px;border-radius:10px;background:var(--naranja-soft);display:flex;align-items:center;justify-content:center;font-size:20px">⚠️</div>
            <div>
                <div style="font-size:14px;font-weight:700;color:var(--ink)">Caso Disciplinario</div>
                <div style="font-size:11.5px;color:var(--muted);margin-top:2px">Faltas de convivencia, conductas, compromisos disciplinarios</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     FORMULARIO ACADÉMICO
════════════════════════════════════════════════ -->
<div id="form-academico">
    <div class="table-card" style="margin-bottom:18px">
        <div class="table-card-header" style="background:var(--verde-soft);border-bottom:0.5px solid var(--verde-line)">
            <div class="table-card-title" style="color:var(--verde-dark)">📚 Ruta del caso académico</div>
        </div>
        <div style="padding:14px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
            <div class="alert alert-info" style="margin:0;font-size:12px">1. Datos del pendiente</div>
            <div class="alert alert-info" style="margin:0;font-size:12px">2. Acción remedial</div>
            <div class="alert alert-warning" style="margin:0;font-size:12px">3. Evidencias y firmas</div>
            <div class="alert alert-success" style="margin:0;font-size:12px">4. Plan de mejoramiento</div>
            <div class="alert alert-info" style="margin:0;font-size:12px">5. Expediente → Comité</div>
        </div>
    </div>

    <div class="case-shell">
        <div class="case-nav">
            <a href="#acad-datos">1. Datos del caso</a>
            <a href="#acad-accion">2. Acción remedial</a>
            <a href="#acad-instancia">3. Plan de mejoramiento</a>
            <a href="#acad-soportes">4. Soportes y notificación</a>
            <a href="#acad-guardar">5. Guardar</a>
        </div>

        <form method="POST" enctype="multipart/form-data" onsubmit="capturarFirmasAsistente()">
            <?= csrfField() ?>
            <input type="hidden" name="tipo_caso" value="Academico">

            <!-- PASO 1 — Datos -->
            <div class="table-card case-section" id="acad-datos" style="margin-bottom:18px">
                <div class="table-card-header">
                    <div>
                        <div class="table-card-title">1. Datos del caso académico</div>
                        <div class="section-kicker">Identifique al aprendiz, competencia y situación académica.</div>
                    </div>
                </div>
                <div class="professional-card-body">
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Aprendiz *</label>
                            <select name="aprendiz_id" id="aprendiz_id_acad" required onchange="llenarCorreoAprendizAcad()">
                                <option value="">-- Seleccionar aprendiz --</option>
                                <?php foreach ($aprendices as $ap): ?>
                                <option value="<?= $ap['id'] ?>" data-email="<?= sanitize($ap['email'] ?? '') ?>"><?= sanitize($ap['nombre']) ?> — <?= sanitize($ap['documento']) ?> / Ficha <?= sanitize($ap['numero_ficha']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tipo de caso académico</label>
                            <select name="tipo_caso" id="tipo_caso_acad">
                                <option value="Academico">Académico</option>
                                <option value="Inasistencia">Inasistencia</option>
                                <option value="Desercion">Deserción</option>
                                <option value="Otro">Otro académico</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Momento del proceso</label>
                            <select name="momento_proceso" id="momento_proceso_acad" onchange="sugerirPlanAcad()">
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
                            <select name="instructor_id" required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ($instructores as $i): ?>
                                <option value="<?= $i['id'] ?>"><?= sanitize($i['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Competencia *</label>
                            <select name="competencia_id" id="competencia_id_acad" required onchange="cargarResultadosAsistente(this.value,'resultado_id_acad')">
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ($competencias as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= sanitize($c['nombre']) ?> — <?= sanitize($c['programa']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Resultado de aprendizaje</label>
                            <select name="resultado_id" id="resultado_id_acad"><option value="">-- Primero seleccione competencia --</option></select>
                        </div>
                        <div class="form-group">
                            <label>Trimestre</label>
                            <select name="trimestre_ocurrencia"><?php for ($t=1;$t<=8;$t++): ?><option value="<?= $t ?>"><?= $t ?> Trimestre</option><?php endfor; ?></select>
                        </div>
                        <div class="form-group">
                            <label>Fecha de registro</label>
                            <input type="date" name="fecha_registro" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group full">
                            <label>Motivo del caso *</label>
                            <textarea name="motivo" required placeholder="Explique qué ocurrió: evidencia perdida, inasistencias, incumplimiento de compromisos, etc."></textarea>
                        </div>
                        <div class="form-group full">
                            <label>Observaciones adicionales</label>
                            <textarea name="observaciones_pendiente" placeholder="Contexto adicional para coordinación o comité."></textarea>
                        </div>
                        <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                            <input type="checkbox" name="debe_repetir" id="debe_repetir" value="1" style="width:auto">
                            <label for="debe_repetir" style="text-transform:none">El aprendiz debe repetir la competencia</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PASO 2 — Acción remedial -->
            <div class="table-card case-section" id="acad-accion" style="margin-bottom:18px">
                <div class="table-card-header">
                    <div>
                        <div class="table-card-title">2. Acción remedial</div>
                        <div class="section-kicker">Documente la estrategia metodológica aplicada. Si no aplica, deje la justificación.</div>
                    </div>
                </div>
                <div class="professional-card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>¿Hubo acción remedial?</label>
                            <select name="hubo_accion" id="hubo_accion_acad" onchange="toggleAccionAcad()">
                                <option>Si</option><option>No</option>
                            </select>
                        </div>
                        <div class="form-group accion-si-acad">
                            <label>Tipo de acción</label>
                            <select name="tipo_accion"><option>Refuerzo presencial</option><option>Tutoría individual</option><option>Taller compensatorio</option><option>Trabajo práctico</option><option>Evaluación oral</option><option>Otro</option></select>
                        </div>
                        <div class="form-group">
                            <label>Fecha de la acción</label>
                            <input type="date" name="fecha_accion" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label>Fecha limite del aprendiz</label>
                            <input type="date" name="fecha_limite_accion">
                        </div>
                        <div class="form-group">
                            <label>Resultado</label>
                            <select name="resultado_accion" id="resultado_accion_acad" onchange="sugerirPlanAcad()">
                                <option>En proceso</option><option>Aprobado</option><option>No aprobado</option>
                            </select>
                        </div>
                        <div class="form-group full accion-si-acad">
                            <label>Descripción de la acción remedial</label>
                            <textarea name="descripcion_accion" placeholder="Detalle la estrategia metodológica y la oportunidad dada al aprendiz."></textarea>
                        </div>
                        <div class="form-group full accion-no-acad" style="display:none">
                            <label>Justificación por no realizar acción remedial</label>
                            <textarea name="justificacion_sin_accion" placeholder="Ej: el aprendiz no asistió a clase; se adjunta control de inasistencia."></textarea>
                        </div>
                        <div class="form-group full">
                            <label>Observaciones</label>
                            <textarea name="observaciones_accion" placeholder="Compromisos, fecha de citación, soporte de inasistencia o detalle para coordinación."></textarea>
                        </div>
                        <div class="form-group full">
                            <label>Soporte de acción o justificación</label>
                            <input type="file" name="soporte_accion" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx">
                        </div>
                        <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                            <input type="checkbox" name="novedad_aprobacion" id="novedad_aprobacion" value="1" style="width:auto">
                            <label for="novedad_aprobacion" style="text-transform:none">Instructor registró novedad de aprobación</label>
                        </div>
                    </div>
                    <div style="margin-top:18px;border-top:0.5px solid var(--line);padding-top:16px">
                        <div class="table-card-title" style="margin-bottom:12px">Firmas de la acción remedial</div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
                            <div>
                                <label>Firma instructor</label>
                                <input type="hidden" name="firma_accion_instructor" id="firma_accion_instructor">
                                <div class="signature-pad-wrap"><canvas id="padAccionInstructor" width="500" height="180"></canvas><div class="signature-placeholder" id="phAccionInstructor">Firmar aquí</div></div>
                                <div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padAccionInstructor','firma_accion_instructor','phAccionInstructor')">Limpiar</button></div>
                            </div>
                            <div>
                                <label>Firma aprendiz</label>
                                <input type="hidden" name="firma_accion_aprendiz" id="firma_accion_aprendiz">
                                <div class="signature-pad-wrap"><canvas id="padAccionAprendiz" width="500" height="180"></canvas><div class="signature-placeholder" id="phAccionAprendiz">Firmar aquí</div></div>
                                <div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padAccionAprendiz','firma_accion_aprendiz','phAccionAprendiz')">Limpiar</button></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PASO 3 — Plan de mejoramiento -->
            <div class="table-card case-section" id="acad-instancia" style="margin-bottom:18px">
                <div class="table-card-header">
                    <div>
                        <div class="table-card-title">3. Plan de mejoramiento (instancia)</div>
                        <div class="section-kicker">Cuando el resultado finalizó y queda no aprobado, registre el acta del plan de mejoramiento.</div>
                    </div>
                </div>
                <div class="professional-card-body">
                    <div class="alert alert-warning">Las instancias se gestionan desde el modulo Instancias para respetar la secuencia oficial del proceso.</div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
                        <input type="checkbox" id="crear_plan" value="1" style="width:auto" disabled>
                        <label for="crear_plan" style="text-transform:none">Crear primera o segunda instancia desde Instancias</label>
                    </div>
                    <div id="planBox" style="display:none">
                        <div class="form-grid">
                            <div class="form-group"><label>Instancia</label><select name="instancia"><option>Primera instancia</option><option>Segunda instancia</option></select></div>
                            <div class="form-group"><label>Fecha concertación</label><input type="date" name="fecha_concertacion" value="<?= date('Y-m-d') ?>"></div>
                            <div class="form-group"><label>Coordinador</label><select name="coordinador_id"><option value="">-- Seleccionar --</option><?php foreach ($coordinadores as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['nombre']) ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Estado plan</label><select name="estado_plan"><option>Abierto</option><option>Cumplido</option><option>No cumplido</option><option>Cerrado</option></select></div>
                            <div class="form-group full" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap">
                                <label style="text-transform:none"><input type="checkbox" name="evidencia_conocimiento" style="width:auto"> Evidencia de conocimiento</label>
                                <label style="text-transform:none"><input type="checkbox" name="evidencia_producto" style="width:auto"> Evidencia de producto</label>
                                <label style="text-transform:none"><input type="checkbox" name="evidencia_desempeno" style="width:auto"> Evidencia de desempeño</label>
                            </div>
                            <div class="form-group full"><label>Plan concertado</label><textarea name="descripcion_plan" placeholder="Orientaciones, estrategias pedagógicas, evidencias requeridas y fechas."></textarea></div>
                            <div class="form-group full"><label>Compromisos</label><textarea name="compromisos_plan" placeholder="Compromisos del aprendiz, instructor y coordinación."></textarea></div>
                            <div class="form-group full"><label>Acta o soporte del plan</label><input type="file" name="soporte_plan" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
                        </div>
                        <div style="margin-top:18px;border-top:0.5px solid var(--line);padding-top:16px">
                            <div class="table-card-title" style="margin-bottom:12px">Firmas del plan</div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
                                <div><label>Instructor</label><input type="hidden" name="firma_plan_instructor" id="firma_plan_instructor"><div class="signature-pad-wrap"><canvas id="padPlanInstructor" width="500" height="180"></canvas><div class="signature-placeholder" id="phPlanInstructor">Firmar aquí</div></div><div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padPlanInstructor','firma_plan_instructor','phPlanInstructor')">Limpiar</button></div></div>
                                <div><label>Coordinador</label><input type="hidden" name="firma_plan_coordinador" id="firma_plan_coordinador"><div class="signature-pad-wrap"><canvas id="padPlanCoordinador" width="500" height="180"></canvas><div class="signature-placeholder" id="phPlanCoordinador">Firmar aquí</div></div><div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padPlanCoordinador','firma_plan_coordinador','phPlanCoordinador')">Limpiar</button></div></div>
                                <div><label>Aprendiz</label><input type="hidden" name="firma_plan_aprendiz" id="firma_plan_aprendiz"><div class="signature-pad-wrap"><canvas id="padPlanAprendiz" width="500" height="180"></canvas><div class="signature-placeholder" id="phPlanAprendiz">Firmar aquí</div></div><div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padPlanAprendiz','firma_plan_aprendiz','phPlanAprendiz')">Limpiar</button></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PASO 4 — Soportes y notificación -->
            <div class="table-card case-section" id="acad-soportes" style="margin-bottom:18px">
                <div class="table-card-header">
                    <div>
                        <div class="table-card-title">4. Soportes y notificación</div>
                        <div class="section-kicker">Adjunte evidencias complementarias y registre que el aprendiz fue informado.</div>
                    </div>
                </div>
                <div class="professional-card-body">
                    <div class="form-grid">
                        <div class="form-group"><label>Tipo de soporte adicional</label><select name="tipo_soporte_general"><option>Control de inasistencia</option><option>Evidencia académica</option><option>Acta</option><option>Otro</option></select></div>
                        <div class="form-group"><label>Archivo adicional</label><input type="file" name="soporte_general" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx"></div>
                        <div class="form-group full"><label>Descripción soporte adicional</label><textarea name="descripcion_soporte_general"></textarea></div>
                        <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                            <input type="checkbox" name="registrar_notificacion" id="registrar_notificacion" value="1" style="width:auto" checked>
                            <label for="registrar_notificacion" style="text-transform:none">Registrar notificación al aprendiz</label>
                        </div>
                        <div class="form-group"><label>Correo del aprendiz</label><input type="email" name="correo_destino" id="correo_destino_acad"></div>
                        <div class="form-group"><label>Asunto</label><input type="text" name="asunto_notificacion" value="Concertación de seguimiento académico"></div>
                        <div class="form-group full"><label>Mensaje de notificación</label><textarea name="mensaje_notificacion" placeholder="Indique fecha, hora, evidencia, lugar o compromiso informado al aprendiz."></textarea></div>
                    </div>
                </div>
            </div>

            <div id="acad-guardar" style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:28px">
                <a href="expediente.php" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Guardar caso académico</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     FORMULARIO DISCIPLINARIO
════════════════════════════════════════════════ -->
<div id="form-disciplinario" style="display:none">
    <div class="table-card" style="margin-bottom:18px">
        <div class="table-card-header" style="background:var(--naranja-soft);border-bottom:0.5px solid var(--naranja-line)">
            <div class="table-card-title" style="color:#7a5500">⚠️ Ruta del caso disciplinario</div>
        </div>
        <div style="padding:14px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
            <div class="alert alert-warning" style="margin:0;font-size:12px">1. Datos de la falta</div>
            <div class="alert alert-warning" style="margin:0;font-size:12px">2. Medida o descargo</div>
            <div class="alert alert-warning" style="margin:0;font-size:12px">3. Compromisos y firmas</div>
            <div class="alert alert-info" style="margin:0;font-size:12px">4. Soportes y notificación</div>
            <div class="alert alert-info" style="margin:0;font-size:12px">5. Expediente → Comité</div>
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

        <form method="POST" enctype="multipart/form-data" onsubmit="capturarFirmasAsistente()">
            <?= csrfField() ?>

            <!-- PASO 1 — Datos de la falta -->
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
                            <select name="aprendiz_id" id="aprendiz_id_disc" required onchange="llenarCorreoAprendizDisc()">
                                <option value="">-- Seleccionar aprendiz --</option>
                                <?php foreach ($aprendices as $ap): ?>
                                <option value="<?= $ap['id'] ?>" data-email="<?= sanitize($ap['email'] ?? '') ?>"><?= sanitize($ap['nombre']) ?> — <?= sanitize($ap['documento']) ?> / Ficha <?= sanitize($ap['numero_ficha']) ?></option>
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
                            <select name="momento_proceso">
                                <option value="Falta leve">Falta leve</option>
                                <option value="Falta grave">Falta grave</option>
                                <option value="Caso grave o excepcional">Caso excepcional</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Estado inicial</label>
                            <select name="estado_pendiente">
                                <option>Pendiente</option><option>En proceso</option><option>Listo para comite</option>
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
                            <select name="trimestre_ocurrencia"><?php for ($t=1;$t<=8;$t++): ?><option value="<?= $t ?>"><?= $t ?> Trimestre</option><?php endfor; ?></select>
                        </div>
                        <div class="form-group">
                            <label>Fecha de registro</label>
                            <input type="date" name="fecha_registro" value="<?= date('Y-m-d') ?>">
                        </div>
                        <!-- Campos académicos ocultos (requeridos por la BD) -->
                        <input type="hidden" name="competencia_id" value="">
                        <input type="hidden" name="resultado_id" value="">
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

            <!-- PASO 2 — Medida o descargo -->
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
                            <select name="hubo_accion" id="hubo_accion_disc" onchange="toggleAccionDisc()">
                                <option value="Si">Sí</option><option value="No">No (pendiente)</option>
                            </select>
                        </div>
                        <div class="form-group accion-si-disc">
                            <label>Tipo de medida</label>
                            <select name="tipo_accion">
                                <option>Llamado de atención verbal</option>
                                <option>Llamado de atención escrito</option>
                                <option>Compromiso de convivencia</option>
                                <option>Descargo del aprendiz</option>
                                <option>Citación a padres/acudiente</option>
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
                        <div class="form-group full accion-si-disc">
                            <label>Descripción de la medida o descargo</label>
                            <textarea name="descripcion_accion" placeholder="Detalle la medida adoptada, el descargo presentado por el aprendiz o el acuerdo alcanzado."></textarea>
                        </div>
                        <div class="form-group full accion-no-disc" style="display:none">
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

            <!-- PASO 3 — Compromisos y firmas -->
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
                            <input type="hidden" name="firma_accion_instructor" id="firma_accion_instructor_disc">
                            <div class="signature-pad-wrap"><canvas id="padDiscInstructor" width="500" height="180"></canvas><div class="signature-placeholder" id="phDiscInstructor">Firmar aquí</div></div>
                            <div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padDiscInstructor','firma_accion_instructor_disc','phDiscInstructor')">Limpiar</button></div>
                        </div>
                        <div>
                            <label>Firma aprendiz</label>
                            <input type="hidden" name="firma_accion_aprendiz" id="firma_accion_aprendiz_disc">
                            <div class="signature-pad-wrap"><canvas id="padDiscAprendiz" width="500" height="180"></canvas><div class="signature-placeholder" id="phDiscAprendiz">Firmar aquí</div></div>
                            <div class="signature-actions"><button type="button" class="btn btn-sm btn-secondary" onclick="clearPadAsistente('padDiscAprendiz','firma_accion_aprendiz_disc','phDiscAprendiz')">Limpiar</button></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PASO 4 — Soportes y notificación -->
            <div class="table-card case-section" id="disc-soportes" style="margin-bottom:18px">
                <div class="table-card-header" style="border-left:3px solid var(--naranja)">
                    <div>
                        <div class="table-card-title">4. Soportes y notificación</div>
                        <div class="section-kicker">Adjunte evidencias del caso disciplinario y notifique al aprendiz.</div>
                    </div>
                </div>
                <div class="professional-card-body">
                    <div class="form-grid">
                        <div class="form-group"><label>Tipo de soporte</label><select name="tipo_soporte_general"><option>Soporte disciplinario</option><option>Acta de compromiso</option><option>Control de asistencia</option><option>Acta</option><option>Otro</option></select></div>
                        <div class="form-group"><label>Archivo soporte</label><input type="file" name="soporte_general" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx"></div>
                        <div class="form-group full"><label>Descripción del soporte</label><textarea name="descripcion_soporte_general"></textarea></div>
                        <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                            <input type="checkbox" name="registrar_notificacion" value="1" style="width:auto" checked>
                            <label style="text-transform:none">Registrar notificación al aprendiz</label>
                        </div>
                        <div class="form-group"><label>Correo del aprendiz</label><input type="email" name="correo_destino" id="correo_destino_disc"></div>
                        <div class="form-group"><label>Asunto</label><input type="text" name="asunto_notificacion" value="Notificación de caso disciplinario"></div>
                        <div class="form-group full"><label>Mensaje de notificación</label><textarea name="mensaje_notificacion" placeholder="Indique la falta, la medida adoptada y los compromisos adquiridos."></textarea></div>
                    </div>
                </div>
            </div>

            <div id="disc-guardar" style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:28px">
                <a href="expediente.php" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary" style="background:var(--naranja);border-color:var(--naranja)">Guardar caso disciplinario</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Tab selector ──
function cambiarTab(tipo) {
    const isAcad = tipo === 'academico';
    document.getElementById('form-academico').style.display    = isAcad ? '' : 'none';
    document.getElementById('form-disciplinario').style.display = isAcad ? 'none' : '';

    const tabAcad = document.getElementById('tab-academico');
    const tabDisc = document.getElementById('tab-disciplinario');
    tabAcad.style.borderTopColor = isAcad ? 'var(--verde)' : '#dde8e1';
    tabAcad.style.boxShadow      = isAcad ? 'var(--shadow-lg)' : 'var(--shadow)';
    tabDisc.style.borderTopColor = isAcad ? '#dde8e1' : 'var(--naranja)';
    tabDisc.style.boxShadow      = isAcad ? 'var(--shadow)' : 'var(--shadow-lg)';
}

// ── Académico ──
function cargarResultadosAsistente(competenciaId, selectId) {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    sel.innerHTML = '<option value="">Cargando...</option>';
    if (!competenciaId) { sel.innerHTML = '<option value="">-- Primero seleccione competencia --</option>'; return; }
    fetch('<?= BASE_URL ?>/ajax/resultados.php?competencia_id=' + competenciaId)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">-- Sin resultado específico --</option>';
            data.forEach(r => { const o = document.createElement('option'); o.value = r.id; o.textContent = r.nombre; sel.appendChild(o); });
        })
        .catch(() => sel.innerHTML = '<option value="">No se pudieron cargar</option>');
}
function llenarCorreoAprendizAcad() {
    const opt = document.getElementById('aprendiz_id_acad').selectedOptions[0];
    document.getElementById('correo_destino_acad').value = opt ? (opt.dataset.email || '') : '';
}
function toggleAccionAcad() {
    const hubo = document.getElementById('hubo_accion_acad').value === 'Si';
    document.querySelectorAll('.accion-si-acad').forEach(el => el.style.display = hubo ? '' : 'none');
    document.querySelectorAll('.accion-no-acad').forEach(el => el.style.display = hubo ? 'none' : '');
}
function togglePlan() {
    document.getElementById('planBox').style.display = document.getElementById('crear_plan').checked ? '' : 'none';
}
function sugerirPlanAcad() {
    const momento  = document.getElementById('momento_proceso_acad').value;
    const resultado = document.getElementById('resultado_accion_acad').value;
    if (momento !== 'Durante el resultado' || resultado === 'No aprobado') {
        document.getElementById('crear_plan').checked = true;
        togglePlan();
    }
}

// ── Disciplinario ──
function llenarCorreoAprendizDisc() {
    const opt = document.getElementById('aprendiz_id_disc').selectedOptions[0];
    document.getElementById('correo_destino_disc').value = opt ? (opt.dataset.email || '') : '';
}
function toggleAccionDisc() {
    const hubo = document.getElementById('hubo_accion_disc').value === 'Si';
    document.querySelectorAll('.accion-si-disc').forEach(el => el.style.display = hubo ? '' : 'none');
    document.querySelectorAll('.accion-no-disc').forEach(el => el.style.display = hubo ? 'none' : '');
}

// ── Firmas compartidas ──
function initPadAsistente(canvasId, hiddenId, placeholderId) {
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
    const b = document.createElement('canvas'); b.width=canvas.width; b.height=canvas.height;
    return canvas.toDataURL() === b.toDataURL();
}
function capturarFirmasAsistente() {
    [
        ['padAccionInstructor','firma_accion_instructor'],
        ['padAccionAprendiz','firma_accion_aprendiz'],
        ['padPlanInstructor','firma_plan_instructor'],
        ['padPlanCoordinador','firma_plan_coordinador'],
        ['padPlanAprendiz','firma_plan_aprendiz'],
        ['padDiscInstructor','firma_accion_instructor_disc'],
        ['padDiscAprendiz','firma_accion_aprendiz_disc'],
    ].forEach(([cId, hId]) => {
        const canvas = document.getElementById(cId);
        const hidden = document.getElementById(hId);
        if (canvas && hidden && !isBlank(canvas)) hidden.value = canvas.toDataURL();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    cambiarTab('academico');
    toggleAccionAcad();
    toggleAccionDisc();
    togglePlan();
    [
        ['padAccionInstructor','firma_accion_instructor','phAccionInstructor'],
        ['padAccionAprendiz','firma_accion_aprendiz','phAccionAprendiz'],
        ['padPlanInstructor','firma_plan_instructor','phPlanInstructor'],
        ['padPlanCoordinador','firma_plan_coordinador','phPlanCoordinador'],
        ['padPlanAprendiz','firma_plan_aprendiz','phPlanAprendiz'],
        ['padDiscInstructor','firma_accion_instructor_disc','phDiscInstructor'],
        ['padDiscAprendiz','firma_accion_aprendiz_disc','phDiscAprendiz'],
    ].forEach(args => initPadAsistente(...args));
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
