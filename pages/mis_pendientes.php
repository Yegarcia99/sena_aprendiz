<?php
// pages/mis_pendientes.php — Vista de pendientes propia del aprendiz (solo lectura)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
require_once __DIR__ . '/../includes/academico_flujo.php';
require_once __DIR__ . '/../includes/disciplinario_schema.php';
requireLogin();

// Solo aprendices acceden aquí
if (!isAprendiz()) {
    header('Location: ' . BASE_URL . '/pages/pendientes.php');
    exit;
}

// ── Primer ingreso: foto obligatoria antes que todo ───────────
$_chkDb   = getDB();
$_chkStmt = $_chkDb->prepare("SELECT debe_subir_foto, debe_cambiar_pass FROM usuarios WHERE id = ? AND activo = 1");
$_chkStmt->execute([(int)(getCurrentUser()['id'] ?? 0)]);
$_chkRow  = $_chkStmt->fetch();
if ($_chkRow) {
    if ((int)$_chkRow['debe_subir_foto'] === 1) {
        header('Location: ' . BASE_URL . '/pages/subir_foto.php');
        exit;
    }
    if ((int)$_chkRow['debe_cambiar_pass'] === 1) {
        header('Location: ' . BASE_URL . '/pages/perfil.php');
        exit;
    }
}

$pageTitle = 'Mis Pendientes';
$db = getDB();
ensureExpedienteSchema($db);
ensureDisciplinarioSchema($db);
$msg = $err = '';

// Obtener el ID del aprendiz vinculado a este usuario
$aprendizId = getAprendizId($db);
if (!$aprendizId) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div style="max-width:480px;margin:60px auto;text-align:center;font-family:\'Nunito\',sans-serif">
        <div style="font-size:52px;margin-bottom:16px">⏳</div>
        <h2 style="color:#1a2e22;font-size:20px;margin:0 0 10px">Cuenta en proceso de vinculación</h2>
        <p style="color:#666;font-size:14px;line-height:1.6;margin:0 0 20px">
            Tu cuenta aún no ha sido vinculada por el administrador.<br>
            Comunícate con tu gestor o coordinador para activar tu acceso.
        </p>
        <a href="' . BASE_URL . '/logout.php" style="background:#39a900;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px">Cerrar Sesión</a>
    </div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Bloquear cualquier intento de modificación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $form = $_POST['form'] ?? 'academico';

    if ($form === 'disciplinario') {
        try {
            $hechoId = (int)($_POST['hecho_id'] ?? 0);
            $atencionId = (int)($_POST['atencion_id'] ?? 0);
            $descripcion = trim($_POST['descripcion_disc'] ?? '');
            if (!$hechoId) {
                throw new RuntimeException('No se pudo identificar el caso disciplinario.');
            }

            $val = $db->prepare("
                SELECT dh.id, dh.tipo_hecho, dh.gestor_id, da.instructor_id
                FROM disc_hechos dh
                LEFT JOIN disc_atenciones da ON da.id=? AND da.hecho_id=dh.id
                WHERE dh.id=? AND dh.aprendiz_id=? AND dh.estado NOT IN ('Cerrado','Remitido a comité')
            ");
            $val->execute([$atencionId ?: 0, $hechoId, $aprendizId]);
            $casoDisc = $val->fetch();
            if (!$casoDisc) {
                throw new RuntimeException('Este caso disciplinario no pertenece a su cuenta o ya fue cerrado.');
            }
            if (empty($_FILES['evidencia_disc']) || ($_FILES['evidencia_disc']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Seleccione el archivo de evidencia disciplinaria.');
            }

            guardarEvidenciaDisciplinaria($db, $_FILES['evidencia_disc'], [
                'hecho_id' => $hechoId,
                'atencion_id' => $atencionId ?: null,
                'aprendiz_id' => $aprendizId,
                'descripcion' => $descripcion,
            ]);

            registrarEventoDisciplinario($db, $hechoId, $aprendizId, 'Evidencia disciplinaria entregada', 'Evidencia entregada', $descripcion, null, null, $atencionId);

            $mensaje = "El aprendiz entrego evidencia para el caso disciplinario: {$casoDisc['tipo_hecho']}."
                . ($descripcion ? "\nObservacion: {$descripcion}" : '');
            $destinos = [(int)$casoDisc['gestor_id']];
            if (!empty($casoDisc['instructor_id'])) {
                $ins = $db->prepare("SELECT usuario_id FROM instructores WHERE id=?");
                $ins->execute([(int)$casoDisc['instructor_id']]);
                $destinos[] = (int)($ins->fetchColumn() ?: 0);
            }
            foreach (array_unique(array_filter($destinos)) as $uidDestino) {
                crearNotificacionDisciplinariaUsuario($db, $aprendizId, $uidDestino, $hechoId, 'Evidencia disciplinaria entregada', $mensaje);
            }

            $msg = 'Evidencia disciplinaria enviada correctamente.';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    } else {
    $pendienteEntregaId = (int)($_POST['pendiente_id'] ?? 0);
    $accionEntregaId = (int)($_POST['accion_id'] ?? 0);
    $observacionEntrega = trim($_POST['observacion_entrega'] ?? '');

    try {
        if (!$pendienteEntregaId || !$accionEntregaId) {
            throw new RuntimeException('No se pudo identificar la accion remedial.');
        }
        if (empty($_FILES['evidencia_entrega']) || ($_FILES['evidencia_entrega']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Seleccione el archivo de evidencia o trabajo.');
        }

        $val = $db->prepare("
            SELECT pa.aprendiz_id
            FROM pendientes_aprendices pa
            JOIN acciones_remediales ar ON ar.pendiente_id = pa.id
            WHERE pa.id=? AND ar.id=? AND pa.aprendiz_id=?
        ");
        $val->execute([$pendienteEntregaId, $accionEntregaId, $aprendizId]);
        if (!$val->fetch()) {
            throw new RuntimeException('Esta accion no pertenece a su cuenta.');
        }

        guardarSoporteExpediente($db, $_FILES['evidencia_entrega'], [
            'aprendiz_id' => $aprendizId,
            'pendiente_id' => $pendienteEntregaId,
            'accion_id' => $accionEntregaId,
            'tipo_soporte' => 'Evidencia entregada por aprendiz',
            'descripcion' => $observacionEntrega,
            'subido_por' => getCurrentUser()['id'] ?? null,
        ]);

        $db->prepare("
            UPDATE acciones_remediales
            SET estado_entrega='Entregada',
                fecha_entrega=NOW(),
                observacion_entrega=?,
                estado_revision='Pendiente',
                fecha_revision=NULL,
                observacion_revision=NULL,
                revisado_por=NULL
            WHERE id=? AND pendiente_id=?
        ")->execute([$observacionEntrega, $accionEntregaId, $pendienteEntregaId]);

        $db->prepare("
            UPDATE pendientes_aprendices
            SET estado='En proceso',
                estado_flujo='Evidencia entregada'
            WHERE id=? AND estado NOT IN ('Superado','Remitido a comité')
        ")->execute([$pendienteEntregaId]);

        registrarEventoAcademico($db, $pendienteEntregaId, $aprendizId, 'Evidencia entregada', 'Evidencia entregada', $observacionEntrega, null, 0, null, $accionEntregaId);

        $info = infoCasoAcademico($db, $pendienteEntregaId);
        if ($info) {
            $mensaje = "El aprendiz {$info['aprendiz_nombre']} entrego evidencia para la competencia {$info['competencia_nombre']}.\n"
                . ($observacionEntrega ? "Observacion: {$observacionEntrega}" : '');
            $destinos = [];
            if (!empty($info['gestor_id']) || !empty($info['ficha_gestor_id'])) {
                $destinos[] = (int)($info['gestor_id'] ?: $info['ficha_gestor_id']);
            }
            $ins = $db->prepare("SELECT usuario_id FROM instructores WHERE id=?");
            $ins->execute([(int)$info['instructor_id']]);
            $instructorUsuarioId = (int)($ins->fetchColumn() ?: 0);
            if ($instructorUsuarioId) {
                $destinos[] = $instructorUsuarioId;
            }
            foreach (array_unique(array_filter($destinos)) as $uidDestino) {
                crearNotificacionInterna($db, $aprendizId, $uidDestino, $pendienteEntregaId, 'Accion', 'Evidencia entregada por aprendiz', $mensaje);
            }
        }

        $msg = 'Evidencia enviada correctamente. Quedo registrada en su expediente.';
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    }
}

// Datos del aprendiz
$stmtApr = $db->prepare("
    SELECT a.nombres, a.apellidos, a.documento, a.estado,
           f.numero_ficha, p.nombre AS programa
    FROM aprendices a
    JOIN fichas f ON f.id = a.ficha_id
    JOIN programas p ON p.id = f.programa_id
    WHERE a.id = ?
");
$stmtApr->execute([$aprendizId]);
$aprendiz = $stmtApr->fetch();

// Filtros
$filtroE = $_GET['estado'] ?? '';
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['p'] ?? 1));
$limit   = 20;
$offset  = ($page - 1) * $limit;

$where  = "pa.aprendiz_id = ?";
$params = [$aprendizId];

if ($filtroE) {
    $where  .= " AND pa.estado = ?";
    $params[] = $filtroE;
}
if ($search) {
    $where  .= " AND (c.nombre LIKE ? OR i.nombres LIKE ? OR i.apellidos LIKE ?)";
    $params  = array_merge($params, array_fill(0, 3, "%$search%"));
}

// Conteo
$cntStmt = $db->prepare("
    SELECT COUNT(*) FROM pendientes_aprendices pa
    JOIN competencias c ON c.id = pa.competencia_id
    JOIN instructores i ON i.id = pa.instructor_id
    WHERE $where
");
$cntStmt->execute($params);
$total = $cntStmt->fetchColumn();
$pages = ceil($total / $limit);

// Lista de pendientes
$stmt = $db->prepare("
    SELECT pa.*,
           c.nombre AS competencia_nombre, c.trimestre AS trimestre_comp,
           CONCAT(i.nombres,' ',i.apellidos) AS instructor_nombre,
           ra.nombre AS resultado_nombre,
           (SELECT GROUP_CONCAT(r2.nombre SEPARATOR ' / ')
            FROM pendiente_resultados pr2 JOIN resultados_aprendizaje r2 ON r2.id=pr2.resultado_id
            WHERE pr2.pendiente_id=pa.id) AS resultados_multi,
           (SELECT COUNT(*) FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id) AS num_acciones,
           (SELECT ar.tipo_accion FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS ultima_accion_tipo,
           (SELECT ar.descripcion FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS ultima_accion_desc,
           (SELECT ar.fecha_limite FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS ultima_fecha_limite,
           (SELECT ar.id FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS ultima_accion_id,
           (SELECT ar.estado_entrega FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS ultima_estado_entrega,
           (SELECT ar.fecha_entrega FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS ultima_fecha_entrega,
           (SELECT ar.estado_revision FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS ultima_estado_revision,
           (SELECT ar.observacion_revision FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS ultima_observacion_revision,
           (SELECT ar.requiere_trabajo FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS requiere_trabajo,
           (SELECT ar.requiere_evidencia FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS requiere_evidencia,
           (SELECT ar.requiere_sustentacion FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS requiere_sustentacion,
           (SELECT ar.requiere_evaluacion FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS requiere_evaluacion,
           (SELECT ar.requiere_tutoria FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS requiere_tutoria,
           (SELECT ar.otra_actividad FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS otra_actividad,
           (SELECT ar.indicaciones_aprendiz FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS indicaciones_aprendiz
    FROM pendientes_aprendices pa
    JOIN competencias c ON c.id = pa.competencia_id
    JOIN instructores i ON i.id = pa.instructor_id
    LEFT JOIN resultados_aprendizaje ra ON ra.id = pa.resultado_id
    WHERE $where
    ORDER BY pa.estado='Pendiente' DESC, pa.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$pendientes = $stmt->fetchAll();

// Resumen rápido
$resumen = $db->prepare("
    SELECT
        SUM(estado IN ('Pendiente','En proceso','No aprobado','Primera instancia','Segunda instancia')) AS activos,
        SUM(estado = 'Superado') AS superados,
        SUM(estado = 'Remitido a comité') AS en_comite,
        SUM(debe_repetir_competencia = 1) AS debe_repetir
    FROM pendientes_aprendices WHERE aprendiz_id = ?
");
$resumen->execute([$aprendizId]);
$res = $resumen->fetch();

$discStmt = $db->prepare("
    SELECT dh.*,
           da.id AS atencion_id,
           da.tipo_atencion,
           da.compromisos,
           da.fecha_seguimiento,
           da.resultado AS resultado_atencion,
           (SELECT COUNT(*) FROM disc_evidencias de WHERE de.hecho_id=dh.id) AS total_evidencias,
           (SELECT de.archivo_nombre FROM disc_evidencias de WHERE de.hecho_id=dh.id ORDER BY de.created_at DESC LIMIT 1) AS ultima_evidencia_nombre,
           (SELECT de.created_at FROM disc_evidencias de WHERE de.hecho_id=dh.id ORDER BY de.created_at DESC LIMIT 1) AS ultima_evidencia_fecha,
           (SELECT de.estado_revision FROM disc_evidencias de WHERE de.hecho_id=dh.id ORDER BY de.created_at DESC LIMIT 1) AS ultima_revision
    FROM disc_hechos dh
    LEFT JOIN disc_atenciones da ON da.id = (
        SELECT da2.id FROM disc_atenciones da2
        WHERE da2.hecho_id=dh.id
        ORDER BY da2.created_at DESC
        LIMIT 1
    )
    WHERE dh.aprendiz_id=?
      AND dh.estado NOT IN ('Cerrado','Remitido a comité')
    ORDER BY dh.fecha_hecho DESC, dh.id DESC
");
$discStmt->execute([$aprendizId]);
$pendientesDisciplinarios = $discStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">📋 Mis Pendientes</div>
        <div class="page-subtitle">
            Hola <strong><?= sanitize($aprendiz['nombres'] ?? '') ?></strong> —
            Ficha <strong><?= sanitize($aprendiz['numero_ficha'] ?? '') ?></strong> ·
            <?= sanitize($aprendiz['programa'] ?? '') ?>
        </div>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<!-- Tarjetas resumen -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));margin-bottom:24px">
    <div class="stat-card" style="border-top:3px solid var(--naranja)">
        <span class="stat-icon" style="background:#fff3e0;color:#e65100">📚</span>
        <div class="stat-value text-warning"><?= (int)$res['activos'] ?></div>
        <div class="stat-label">Pendientes activos</div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--verde)">
        <span class="stat-icon" style="background:var(--verde-pale);color:var(--verde-dark)">✅</span>
        <div class="stat-value text-success"><?= (int)$res['superados'] ?></div>
        <div class="stat-label">Superados</div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--rojo)">
        <span class="stat-icon" style="background:#ffebee;color:#c62828">⚠️</span>
        <div class="stat-value text-danger"><?= (int)$res['en_comite'] ?></div>
        <div class="stat-label">En comité</div>
    </div>
    <div class="stat-card" style="border-top:3px solid #9c27b0">
        <span class="stat-icon" style="background:#f3e5f5;color:#6a1b9a">🔄</span>
        <div class="stat-value" style="color:#6a1b9a"><?= (int)$res['debe_repetir'] ?></div>
        <div class="stat-label">Debe repetir</div>
    </div>
</div>

<!-- Filtros -->
<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="text" name="q" class="search-input" placeholder="Buscar competencia o instructor..." value="<?= sanitize($search) ?>">
        <select name="estado" class="search-input" style="min-width:180px">
            <option value="">Todos los estados</option>
            <?php foreach (['Pendiente','En proceso','No aprobado','Primera instancia','Segunda instancia','Listo para comite','Superado','Remitido a comité'] as $e): ?>
            <option value="<?= $e ?>" <?= $filtroE === $e ? 'selected' : '' ?>><?= $e ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
        <?php if ($filtroE || $search): ?>
        <a href="mis_pendientes.php" class="btn btn-sm" style="background:#eee;color:#666">✕ Limpiar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabla -->
<style>
.instancia-row { background:#fff8e8; }
.instancia-alerta {
    margin-top:7px;display:inline-flex;align-items:center;gap:6px;padding:5px 8px;
    border-radius:8px;background:#fff1d6;border:1px solid #f0b45a;color:#8a4a00;
    font-size:10.5px;font-weight:800;line-height:1.2;
}
.instancia-sirena {
    width:9px;height:9px;border-radius:50%;background:#e53935;
    box-shadow:0 0 0 0 rgba(229,57,53,.45);animation:alarmaInstancia 1.25s infinite;flex:0 0 auto;
}
@keyframes alarmaInstancia {
    0% { box-shadow:0 0 0 0 rgba(229,57,53,.45); }
    70% { box-shadow:0 0 0 7px rgba(229,57,53,0); }
    100% { box-shadow:0 0 0 0 rgba(229,57,53,0); }
}
</style>
<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Mis Pendientes (<?= $total ?>)</div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Competencia / Resultado</th>
                    <th>Instructor</th>
                    <th>Trimestre</th>
                    <th>Fecha Registro</th>
                    <th>¿Repite?</th>
                    <th>Estado</th>
                    <th>Accion pendiente</th>
                    <th>Fecha limite</th>
                    <th>Respuesta</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pendientes)): ?>
                <tr><td colspan="10">
                    <div class="empty-state">
                        <div class="icon">✅</div>
                        <p>¡No tienes pendientes<?= $filtroE ? " con estado \"$filtroE\"" : '' ?>!</p>
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
                $enInstancia = in_array($p['estado_flujo'] ?? '', ['Primera instancia','Segunda instancia'], true);
                $textoInstancia = ($p['estado_flujo'] ?? '') === 'Segunda instancia' ? 'SEGUNDA INSTANCIA' : 'PRIMERA INSTANCIA';
            ?>
                <tr class="<?= $enInstancia ? 'instancia-row' : '' ?>">
                    <td>
                        <strong><?= sanitize($p['competencia_nombre']) ?></strong>
                        <?php
                        $resDisplay = $p['resultados_multi'] ?? $p['resultado_nombre'] ?? '';
                        if ($resDisplay): ?>
                        <br><small style="color:#555">↳ <?= sanitize($resDisplay) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px"><?= sanitize($p['instructor_nombre']) ?></td>
                    <td style="text-align:center"><?= (int)$p['trimestre_ocurrencia'] ?>° trim.</td>
                    <td style="font-size:12px"><?= date('d/m/Y', strtotime($p['fecha_registro'])) ?></td>
                    <td style="text-align:center">
                        <?= $p['debe_repetir_competencia']
                            ? '<span style="color:var(--rojo);font-weight:700">Sí</span>'
                            : '<span style="color:#aaa">No</span>' ?>
                    </td>
                    <td>
                        <span class="badge <?= $estadoClass ?>"><?= sanitize($p['estado']) ?></span>
                        <?php if ($enInstancia): ?>
                        <div class="instancia-alerta" title="Este pendiente ya fue escalado por el gestor">
                            <span class="instancia-sirena"></span>
                            <?= $textoInstancia ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($p['ultima_accion_tipo']): ?>
                        <strong style="font-size:12px;color:var(--verde-dark)"><?= sanitize($p['ultima_accion_tipo']) ?></strong>
                        <br><small style="color:#666"><?= sanitize(mb_strimwidth($p['ultima_accion_desc'] ?? '', 0, 70, '...')) ?></small>
                        <?php
                        $actividades = [];
                        if (!empty($p['requiere_trabajo'])) $actividades[] = 'Trabajo escrito';
                        if (!empty($p['requiere_evidencia'])) $actividades[] = 'Subir evidencia';
                        if (!empty($p['requiere_sustentacion'])) $actividades[] = 'Sustentar/exponer';
                        if (!empty($p['requiere_evaluacion'])) $actividades[] = 'Evaluacion';
                        if (!empty($p['requiere_tutoria'])) $actividades[] = 'Tutoria';
                        if (!empty($p['otra_actividad'])) $actividades[] = $p['otra_actividad'];
                        ?>
                        <?php if ($actividades): ?>
                        <div style="margin-top:6px;display:flex;gap:4px;flex-wrap:wrap;justify-content:center">
                            <?php foreach ($actividades as $act): ?>
                            <span class="badge badge-proceso" style="font-size:10px"><?= sanitize($act) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($p['indicaciones_aprendiz'])): ?>
                        <div style="margin-top:6px;font-size:11px;color:#444;background:#f7faf8;border:1px solid #e0ede6;border-radius:6px;padding:6px;text-align:left">
                            <?= nl2br(sanitize($p['indicaciones_aprendiz'])) ?>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="font-size:12px;color:#aaa">Sin accion asignada</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;text-align:center">
                        <?php if ($p['ultima_fecha_limite']): ?>
                        <?php $vencida = strtotime($p['ultima_fecha_limite']) < strtotime(date('Y-m-d')) && !in_array($p['estado'], ['Superado','Remitido a comité']); ?>
                        <span style="font-weight:700;color:<?= $vencida ? 'var(--rojo)' : 'var(--naranja)' ?>">
                            <?= date('d/m/Y', strtotime($p['ultima_fecha_limite'])) ?>
                        </span>
                        <?php else: ?>
                        <span style="color:#bbb">--</span>
                        <?php endif; ?>
                    </td>
                    <td style="min-width:240px">
                        <?php if (($p['ultima_estado_entrega'] ?? '') === 'Aprobada'): ?>
                        <span class="badge badge-superado">Aprobada</span>
                        <?php if ($p['ultima_fecha_entrega']): ?>
                        <br><small style="color:#666"><?= date('d/m/Y H:i', strtotime($p['ultima_fecha_entrega'])) ?></small>
                        <?php endif; ?>
                        <?php elseif (($p['ultima_estado_entrega'] ?? '') === 'Entregada'): ?>
                        <span class="badge badge-superado">Entregada</span>
                        <?php if ($p['ultima_fecha_entrega']): ?>
                        <br><small style="color:#666"><?= date('d/m/Y H:i', strtotime($p['ultima_fecha_entrega'])) ?></small>
                        <?php endif; ?>
                        <?php elseif (($p['ultima_estado_entrega'] ?? '') === 'Correccion solicitada'): ?>
                        <span class="badge badge-pendiente">Correccion solicitada</span>
                        <?php if (!empty($p['ultima_observacion_revision'])): ?>
                        <div style="margin:6px 0;font-size:11px;color:#8a4a00;background:#fff8e8;border:1px solid #f0b45a;border-radius:6px;padding:6px">
                            <?= nl2br(sanitize($p['ultima_observacion_revision'])) ?>
                        </div>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data" style="display:grid;gap:6px;margin-top:6px">
                            <?= csrfField() ?>
                            <input type="hidden" name="pendiente_id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="accion_id" value="<?= (int)$p['ultima_accion_id'] ?>">
                            <input type="file" name="evidencia_entrega" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx,.zip,.rar" required style="font-size:11px">
                            <textarea name="observacion_entrega" placeholder="Explique la correccion realizada..." style="min-height:42px;font-size:11px"></textarea>
                            <button type="submit" class="btn btn-sm btn-primary">Enviar correccion</button>
                        </form>
                        <?php elseif (($p['ultima_estado_entrega'] ?? '') === 'No aprobada'): ?>
                        <span class="badge badge-pendiente">No aprobada</span>
                        <?php if (!empty($p['ultima_observacion_revision'])): ?>
                        <div style="margin-top:6px;font-size:11px;color:#8a4a00;background:#fff8e8;border:1px solid #f0b45a;border-radius:6px;padding:6px">
                            <?= nl2br(sanitize($p['ultima_observacion_revision'])) ?>
                        </div>
                        <?php endif; ?>
                        <?php elseif ($p['ultima_accion_id'] && !in_array($p['estado'], ['Superado','Remitido a comitÃ©'])): ?>
                        <form method="POST" enctype="multipart/form-data" style="display:grid;gap:6px">
                            <?= csrfField() ?>
                            <input type="hidden" name="pendiente_id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="accion_id" value="<?= (int)$p['ultima_accion_id'] ?>">
                            <input type="file" name="evidencia_entrega" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx,.zip,.rar" required style="font-size:11px">
                            <textarea name="observacion_entrega" placeholder="Observacion para el instructor..." style="min-height:42px;font-size:11px"></textarea>
                            <button type="submit" class="btn btn-sm btn-primary">Enviar evidencia</button>
                        </form>
                        <?php else: ?>
                        <span style="font-size:12px;color:#aaa">Sin respuesta requerida</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#666;max-width:200px">
                        <?= $p['motivo'] ? sanitize(mb_strimwidth($p['motivo'], 0, 80, '…')) : '<span style="color:#bbb">—</span>' ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?p=<?= $i ?>&q=<?= urlencode($search) ?>&estado=<?= urlencode($filtroE) ?>"
           class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<div class="table-card" style="margin-top:22px">
    <div class="table-card-header">
        <div>
            <div class="table-card-title">Pendientes disciplinarios (<?= count($pendientesDisciplinarios) ?>)</div>
            <div style="font-size:12px;color:var(--muted)">Compromisos, descargos o evidencias solicitadas dentro del seguimiento disciplinario.</div>
        </div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo / gravedad</th>
                    <th>Estado</th>
                    <th>Compromiso o atención</th>
                    <th>Fecha limite</th>
                    <th>Evidencia</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pendientesDisciplinarios)): ?>
                <tr><td colspan="6"><div class="empty-state"><p>No tienes pendientes disciplinarios activos.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($pendientesDisciplinarios as $d): ?>
                <?php
                $fechaLimiteDisc = $d['fecha_seguimiento'] ?: $d['fecha_limite_atencion'];
                $vencidoDisc = $fechaLimiteDisc && strtotime($fechaLimiteDisc) < strtotime(date('Y-m-d'));
                ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($d['fecha_hecho'])) ?></td>
                    <td>
                        <strong><?= sanitize($d['tipo_hecho']) ?></strong><br>
                        <small><?= sanitize($d['gravedad'] ?? 'Falta leve') ?></small>
                    </td>
                    <td>
                        <span class="badge badge-proceso"><?= sanitize($d['estado_flujo'] ?: $d['estado']) ?></span><br>
                        <small><?= sanitize($d['estado']) ?></small>
                    </td>
                    <td style="font-size:12px;max-width:320px">
                        <?php if (!empty($d['tipo_atencion'])): ?>
                            <strong><?= sanitize($d['tipo_atencion']) ?></strong><br>
                        <?php endif; ?>
                        <?= !empty($d['compromisos'])
                            ? nl2br(sanitize($d['compromisos']))
                            : nl2br(sanitize(mb_strimwidth($d['descripcion'] ?? '', 0, 160, '...'))) ?>
                    </td>
                    <td style="font-size:12px">
                        <?php if ($fechaLimiteDisc): ?>
                            <strong style="color:<?= $vencidoDisc ? 'var(--rojo)' : 'var(--naranja)' ?>"><?= date('d/m/Y', strtotime($fechaLimiteDisc)) ?></strong>
                        <?php else: ?>
                            <span style="color:#bbb">Sin fecha</span>
                        <?php endif; ?>
                    </td>
                    <td style="min-width:260px">
                        <?php if ((int)$d['total_evidencias'] > 0): ?>
                            <span class="badge badge-superado">Entregada</span>
                            <br><small style="color:#666"><?= sanitize($d['ultima_evidencia_nombre'] ?? '') ?></small>
                            <?php if (!empty($d['ultima_evidencia_fecha'])): ?>
                                <br><small style="color:#666"><?= date('d/m/Y H:i', strtotime($d['ultima_evidencia_fecha'])) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($d['ultima_revision'])): ?>
                                <br><small>Revision: <?= sanitize($d['ultima_revision']) ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data" style="display:grid;gap:6px;margin-top:8px">
                            <?= csrfField() ?>
                            <input type="hidden" name="form" value="disciplinario">
                            <input type="hidden" name="hecho_id" value="<?= (int)$d['id'] ?>">
                            <input type="hidden" name="atencion_id" value="<?= (int)($d['atencion_id'] ?? 0) ?>">
                            <input type="file" name="evidencia_disc" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx,.zip,.rar" required style="font-size:11px">
                            <textarea name="descripcion_disc" placeholder="Explique lo que entrega o sus descargos..." style="min-height:42px;font-size:11px"></textarea>
                            <button type="submit" class="btn btn-sm btn-primary"><?= (int)$d['total_evidencias'] > 0 ? 'Enviar nueva evidencia' : 'Enviar evidencia' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
