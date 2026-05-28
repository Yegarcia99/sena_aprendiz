<?php
// pages/seguimiento_academico.php - Escalamiento academico por gestor/coordinacion
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
require_once __DIR__ . '/../includes/academico_flujo.php';
requireLogin();
denyIfInstructorOrAprendiz();

$pageTitle = 'Seguimiento Academico';
$db = getDB();
ensureExpedienteSchema($db);
$user = getCurrentUser();
$msg = $err = '';
$ff = filtroFichas($db, 'a');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pendienteId = (int)($_POST['pendiente_id'] ?? 0);
    $accion = $_POST['accion_flujo'] ?? '';
    $observacion = trim($_POST['observacion'] ?? '');

    $info = $pendienteId ? infoCasoAcademico($db, $pendienteId) : null;
    if (!$info) {
        $err = 'No se encontro el caso academico.';
    } else {
        $estadoAnterior = $info['estado_flujo'] ?? 'Reportado';
        if ($accion === 'primera') {
            $db->prepare("
                UPDATE pendientes_aprendices
                SET estado='En proceso',
                    estado_flujo='Primera instancia',
                    instancia_actual=1,
                    fecha_primera_instancia=CURDATE(),
                    coordinador_id=COALESCE(coordinador_id, ?)
                WHERE id=?
            ")->execute([(int)($user['id'] ?? 0), $pendienteId]);
            registrarEventoAcademico($db, $pendienteId, (int)$info['aprendiz_id'], 'Primera instancia', 'Primera instancia', $observacion, $estadoAnterior, 1);
            notificarInstanciaAcademica($db, $pendienteId, 1, $observacion);
            $msg = 'Caso enviado a primera instancia y aprendiz notificado.';
        } elseif ($accion === 'segunda') {
            $db->prepare("
                UPDATE pendientes_aprendices
                SET estado='En proceso',
                    estado_flujo='Segunda instancia',
                    instancia_actual=2,
                    fecha_segunda_instancia=CURDATE(),
                    coordinador_id=COALESCE(coordinador_id, ?)
                WHERE id=?
            ")->execute([(int)($user['id'] ?? 0), $pendienteId]);
            registrarEventoAcademico($db, $pendienteId, (int)$info['aprendiz_id'], 'Segunda instancia', 'Segunda instancia', $observacion, $estadoAnterior, 2);
            notificarInstanciaAcademica($db, $pendienteId, 2, $observacion);
            $msg = 'Caso enviado a segunda instancia y aprendiz notificado.';
        } elseif ($accion === 'comite') {
            $motivo = $observacion ?: 'No presento los pendientes luego de segunda instancia.';
            $db->prepare("
                UPDATE pendientes_aprendices
                SET estado='En proceso',
                    estado_flujo='Listo para comite',
                    habilitado_comite=1,
                    fecha_habilitado_comite=NOW(),
                    motivo_habilitado_comite=?
                WHERE id=?
            ")->execute([$motivo, $pendienteId]);
            registrarEventoAcademico($db, $pendienteId, (int)$info['aprendiz_id'], 'Habilitado para comite', 'Listo para comite', $motivo, $estadoAnterior, 2);
            notificarCasoListoComite($db, $pendienteId, $motivo);
            $msg = 'Caso habilitado para comite.';
        } else {
            $err = 'Seleccione una accion valida.';
        }
    }
}

$where = "pa.tipo_caso <> 'Disciplinario' AND pa.estado NOT IN ('Superado','Remitido a comitÃ©') AND pa.estado_flujo NOT IN ('Superado','Remitido a comite')";
$params = [];
if (!empty($ff['params'])) {
    $where .= ' AND ' . $ff['sql'];
    $params = array_merge($params, $ff['params']);
}
$filtro = $_GET['filtro'] ?? 'activos';
if ($filtro === 'vencidos') {
    $where .= " AND pa.fecha_limite_actual IS NOT NULL AND pa.fecha_limite_actual < CURDATE()";
} elseif ($filtro === 'comite') {
    $where .= " AND pa.habilitado_comite=1";
}

$stmt = $db->prepare("
    SELECT pa.*,
           CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre,
           a.documento,
           f.numero_ficha,
           c.nombre AS competencia_nombre,
           CONCAT(i.nombres,' ',i.apellidos) AS instructor_nombre,
           (SELECT ar.tipo_accion FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS accion_tipo,
           (SELECT ar.descripcion FROM acciones_remediales ar WHERE ar.pendiente_id=pa.id ORDER BY ar.created_at DESC LIMIT 1) AS accion_desc
    FROM pendientes_aprendices pa
    JOIN aprendices a ON a.id=pa.aprendiz_id
    JOIN fichas f ON f.id=a.ficha_id
    JOIN competencias c ON c.id=pa.competencia_id
    JOIN instructores i ON i.id=pa.instructor_id
    WHERE $where
    ORDER BY pa.habilitado_comite DESC, pa.fecha_limite_actual IS NULL ASC, pa.fecha_limite_actual ASC, pa.updated_at DESC
");
$stmt->execute($params);
$casos = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Seguimiento Academico</div>
        <div class="page-subtitle">Primera instancia, segunda instancia y habilitacion para comite</div>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
    <a href="seguimiento_academico.php?filtro=activos" class="btn btn-sm <?= $filtro==='activos'?'btn-primary':'btn-secondary' ?>">Activos</a>
    <a href="seguimiento_academico.php?filtro=vencidos" class="btn btn-sm <?= $filtro==='vencidos'?'btn-primary':'btn-secondary' ?>">Vencidos</a>
    <a href="seguimiento_academico.php?filtro=comite" class="btn btn-sm <?= $filtro==='comite'?'btn-primary':'btn-secondary' ?>">Listos para comite</a>
</div>

<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Casos academicos (<?= count($casos) ?>)</div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Aprendiz</th>
                    <th>Ficha</th>
                    <th>Competencia</th>
                    <th>Accion remedial</th>
                    <th>Limite</th>
                    <th>Flujo</th>
                    <th>Decision</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($casos)): ?>
                <tr><td colspan="7"><div class="empty-state"><p>No hay casos academicos en este filtro.</p></div></td></tr>
            <?php else: foreach ($casos as $c):
                $vencido = $c['fecha_limite_actual'] && strtotime($c['fecha_limite_actual']) < strtotime(date('Y-m-d'));
            ?>
                <tr>
                    <td><strong><?= sanitize($c['aprendiz_nombre']) ?></strong><br><small style="color:#888"><?= sanitize($c['documento']) ?></small></td>
                    <td><?= sanitize($c['numero_ficha']) ?></td>
                    <td style="font-size:12px"><?= sanitize($c['competencia_nombre']) ?><br><small><?= sanitize($c['instructor_nombre']) ?></small></td>
                    <td style="font-size:12px">
                        <strong><?= sanitize($c['accion_tipo'] ?: 'Sin accion') ?></strong>
                        <?php if ($c['accion_desc']): ?><br><small><?= sanitize(mb_strimwidth($c['accion_desc'], 0, 80, '...')) ?></small><?php endif; ?>
                    </td>
                    <td style="font-size:12px;font-weight:700;color:<?= $vencido ? 'var(--rojo)' : 'var(--naranja)' ?>">
                        <?= $c['fecha_limite_actual'] ? date('d/m/Y', strtotime($c['fecha_limite_actual'])) : '--' ?>
                    </td>
                    <td><span class="badge <?= $vencido ? 'badge-pendiente' : 'badge-proceso' ?>"><?= sanitize($c['estado_flujo']) ?></span></td>
                    <td>
                        <form method="POST" style="display:grid;gap:6px;min-width:220px">
                            <?= csrfField() ?>
                            <input type="hidden" name="pendiente_id" value="<?= (int)$c['id'] ?>">
                            <textarea name="observacion" placeholder="Observacion breve..." style="min-height:48px;font-size:12px"></textarea>
                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                <?php if ((int)$c['instancia_actual'] < 1): ?>
                                <button name="accion_flujo" value="primera" class="btn btn-sm btn-primary">1ra instancia</button>
                                <?php elseif ((int)$c['instancia_actual'] < 2): ?>
                                <button name="accion_flujo" value="segunda" class="btn btn-sm btn-primary">2da instancia</button>
                                <?php endif; ?>
                                <button name="accion_flujo" value="comite" class="btn btn-sm btn-secondary">Listo comite</button>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
