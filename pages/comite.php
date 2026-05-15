<?php
// pages/comite.php - Aprendices remitidos a Comité
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
require_once __DIR__ . '/../includes/notificaciones.php';
requireLogin();
$pageTitle = 'Comité Académico';
$db  = getDB();
ensureExpedienteSchema($db);
$msg = $err = '';
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$aprendizPreselect = (int)($_GET['aprendiz_id'] ?? 0);

// ── GUARDAR ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'aprendiz_id'        => (int)($_POST['aprendiz_id'] ?? 0),
        'fecha_remision'     => $_POST['fecha_remision'] ?? date('Y-m-d'),
        'motivo_remision'    => trim($_POST['motivo_remision'] ?? ''),
        'decision'           => $_POST['decision'] ?? 'Pendiente',
        'caso_excepcional'   => isset($_POST['caso_excepcional']) ? 1 : 0,
        'observaciones_comite' => trim($_POST['observaciones_comite'] ?? ''),
        'validacion_expediente' => '',
    ];
    if (!$data['aprendiz_id'] || !$data['motivo_remision']) {
        $err = 'Complete los campos obligatorios.';
    } else {
        $diag = diagnosticoExpediente($db, $data['aprendiz_id']);
        if (!$diag['completo'] && !$data['caso_excepcional']) {
            $err = 'El expediente esta incompleto. Faltantes: ' . implode(' ', $diag['faltantes']) . ' Marque caso excepcional si debe pasar directamente a comite.';
        } else {
        $data['validacion_expediente'] = $diag['completo'] ? 'Expediente completo' : ('Caso excepcional: ' . implode(' ', $diag['faltantes']));
        $editId = (int)($_POST['edit_id'] ?? 0);
        if ($editId) {
            $stmt = $db->prepare("UPDATE comite_aprendices SET aprendiz_id=?,fecha_remision=?,motivo_remision=?,decision=?,caso_excepcional=?,observaciones_comite=?,validacion_expediente=? WHERE id=?");
            $stmt->execute([...array_values($data), $editId]);
            // Actualizar estado en pendientes si hay decisión
            if ($data['decision'] !== 'Pendiente') {
                $db->prepare("UPDATE pendientes_aprendices SET estado='Remitido a comité' WHERE aprendiz_id=? AND estado='Pendiente'")->execute([$data['aprendiz_id']]);
            }
            $msg = 'Registro de comité actualizado.';
        } else {
            $stmt = $db->prepare("INSERT INTO comite_aprendices (aprendiz_id,fecha_remision,motivo_remision,decision,caso_excepcional,observaciones_comite,validacion_expediente) VALUES(?,?,?,?,?,?,?)");
            $stmt->execute(array_values($data));
            $db->prepare("UPDATE pendientes_aprendices SET estado='Remitido a comité' WHERE aprendiz_id=? AND estado NOT IN ('Superado','Cerrado')")->execute([$data['aprendiz_id']]);
            $msg = 'Aprendiz remitido a comité correctamente.';

            // ── Alerta automática a coordinadores y administradores ──
            $infoApr = $db->prepare("SELECT CONCAT(a.nombres,' ',a.apellidos) AS nombre FROM aprendices a WHERE a.id=?");
            $infoApr->execute([$data['aprendiz_id']]);
            $nombreApr = $infoApr->fetchColumn();
            $coords = $db->query("SELECT id, email, CONCAT(nombres,' ',apellidos) AS nombre FROM usuarios WHERE rol IN ('Administrador','Coordinador') AND activo=1")->fetchAll();
            $userActual = getCurrentUser();
            foreach ($coords as $coord) {
                $asunto  = "🔔 Nuevo caso en comité: {$nombreApr}";
                $cuerpo  = "Se ha remitido al aprendiz {$nombreApr} a comité académico.\n\n";
                $cuerpo .= "Motivo: {$data['motivo_remision']}\n";
                $cuerpo .= "Fecha: " . date('d/m/Y') . "\n";
                $cuerpo .= $data['caso_excepcional'] ? "⚠️ Marcado como caso excepcional.\n" : "";
                $cuerpo .= "\nExpediente: {$data['validacion_expediente']}";
                crearAlertaUsuario(
                    $db,
                    $data['aprendiz_id'],
                    $asunto,
                    $cuerpo,
                    $coord['email'] ?? '',
                    0,
                    'Comité',
                    0,
                    $userActual['id'] ?? 0
                );
            }
        }
        $action = 'list';
        }
    }
}

if ($action === 'delete' && $id) {
    verifyCsrf();
    try {
        $db->prepare("DELETE FROM comite_aprendices WHERE id=?")->execute([$id]);
        $msg = 'Registro de comité eliminado correctamente.';
    } catch (PDOException $e) {
        $err = 'Error al eliminar el registro: ' . $e->getMessage();
    }
    $action = 'list';
}

$aprendices = $db->query("
    SELECT a.id, CONCAT(a.apellidos,', ',a.nombres) AS nombre, a.documento,
           COUNT(pa.id) AS pendientes
    FROM aprendices a
    LEFT JOIN pendientes_aprendices pa ON pa.aprendiz_id=a.id AND pa.estado NOT IN('Superado')
    WHERE a.estado='Activo'
    GROUP BY a.id
    ORDER BY a.apellidos
")->fetchAll();

// Listado
$stmt = $db->query("
    SELECT ca.*, CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre, a.documento,
           f.numero_ficha, p.nombre AS programa,
           COUNT(pa.id) AS num_pendientes
    FROM comite_aprendices ca
    JOIN aprendices a ON a.id=ca.aprendiz_id
    JOIN fichas f ON f.id=a.ficha_id
    JOIN programas p ON p.id=f.programa_id
    LEFT JOIN pendientes_aprendices pa ON pa.aprendiz_id=ca.aprendiz_id AND pa.estado NOT IN('Superado')
    GROUP BY ca.id
    ORDER BY ca.created_at DESC
");
$comite = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <div class="page-title">Comité Académico</div>
        <div class="page-subtitle">Aprendices remitidos a comité para evaluación de su proceso formativo</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/ajax/exportar_excel.php?tipo=comite" class="btn btn-excel">↓ Exportar Excel</a>
        <button class="btn btn-primary" onclick="openModal('modalComite')">+ Remitir a Comité</button>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<?php if (empty($comite)): ?>
<div class="table-card">
    <div class="empty-state" style="padding:80px 20px">
        <div class="icon">◑</div>
        <p>No hay aprendices remitidos a comité.</p>
        <button class="btn btn-primary" style="margin-top:16px" onclick="openModal('modalComite')">Remitir primer aprendiz</button>
    </div>
</div>
<?php else: ?>
<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Registro de Comité (<?= count($comite) ?>)</div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Aprendiz</th>
                    <th>Ficha</th>
                    <th>Fecha Remisión</th>
                    <th>Pendientes activos</th>
                    <th>Decisión</th>
                    <th>Motivo</th>
                    <th>Opciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($comite as $c):
                $decCls = match($c['decision']) {
                    'Continúa' => 'badge-superado',
                    'Aplaza','Retira' => 'badge-pendiente',
                    default    => 'badge-proceso'
                };
            ?>
                <tr>
                    <td>
                        <strong><?= sanitize($c['aprendiz_nombre']) ?></strong><br>
                        <small style="color:#888"><?= sanitize($c['documento']) ?></small>
                    </td>
                    <td><?= sanitize($c['numero_ficha']) ?></td>
                    <td><?= date('d/m/Y', strtotime($c['fecha_remision'])) ?></td>
                    <td style="text-align:center;font-weight:700;color:var(--rojo)"><?= $c['num_pendientes'] ?></td>
                    <td><span class="badge <?= $decCls ?>"><?= sanitize($c['decision']) ?></span></td>
                    <td style="max-width:200px;font-size:12px"><?= sanitize(substr($c['motivo_remision'],0,80)) ?>...</td>
                    <td>
                        <a href="expediente.php?aprendiz_id=<?=$c['aprendiz_id']?>" class="btn btn-sm btn-primary">Exp.</a>
                        <button onclick='editComite(<?= json_encode($c, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn btn-sm btn-secondary">✎</button>
                        <button onclick='confirmDelete("comite.php?action=delete&id=<?=$c['id']?>","este registro")' class="btn btn-sm btn-danger">✕</button>
                    </td>
                </tr>
                <?php if ($c['observaciones_comite']): ?>
                <tr style="background:var(--gris-bg)">
                    <td colspan="7" style="padding:6px 16px 10px 36px;font-size:12px;color:var(--gris-text)">
                        <strong>Resolución del comité:</strong> <?= sanitize($c['observaciones_comite']) ?>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- MODAL COMITÉ -->
<div class="modal-overlay" id="modalComite">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="titleComite">Remitir a Comité Académico</div>
            <button class="modal-close" onclick="closeModal('modalComite')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="edit_id" id="ecId" value="0">
            <div class="modal-body">
                <div class="alert alert-warning">
                    Se debe remitir a comité cuando el aprendiz tiene 3 o más pendientes o cuando las acciones remediales no han sido efectivas.
                </div>
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Aprendiz *</label>
                        <select name="aprendiz_id" id="ec_aprendiz" required>
                            <option value="">-- Seleccionar aprendiz --</option>
                            <?php foreach ($aprendices as $ap): ?>
                            <option value="<?=$ap['id']?>" <?=$aprendizPreselect===$ap['id']?'selected':''?>><?= sanitize($ap['nombre']) ?> (<?= sanitize($ap['documento']) ?>) - <?=$ap['pendientes']?> pendiente(s)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha de Remisión *</label>
                        <input type="date" name="fecha_remision" id="ec_fecha" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Decisión del Comité</label>
                        <select name="decision" id="ec_decision">
                            <option>Pendiente</option>
                            <option>Continúa</option>
                            <option>Aplaza</option>
                            <option>Retira</option>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label>Motivo de Remisión *</label>
                        <textarea name="motivo_remision" id="ec_motivo" required placeholder="Detalle el motivo por el que se remite al comité: número de pendientes, acciones realizadas sin éxito, etc."></textarea>
                    </div>
                    <div class="form-group full" style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="caso_excepcional" id="ec_excepcional" value="1" style="width:auto">
                        <label for="ec_excepcional" style="text-transform:none;font-size:13px">Caso excepcional o grave: permitir remision aunque falten soportes</label>
                    </div>
                    <div class="form-group full">
                        <label>Observaciones del Comité</label>
                        <textarea name="observaciones_comite" id="ec_obs" placeholder="Decisiones, compromisos y seguimiento acordados en el comité..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalComite')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Registro</button>
            </div>
        </form>
    </div>
</div>
<script>
function editComite(d) {
    document.getElementById('titleComite').textContent = 'Editar Registro de Comité';
    document.getElementById('ecId').value        = d.id;
    document.getElementById('ec_aprendiz').value = d.aprendiz_id;
    document.getElementById('ec_fecha').value    = d.fecha_remision;
    document.getElementById('ec_decision').value = d.decision;
    document.getElementById('ec_excepcional').checked = d.caso_excepcional == 1;
    document.getElementById('ec_motivo').value   = d.motivo_remision || '';
    document.getElementById('ec_obs').value      = d.observaciones_comite || '';
    openModal('modalComite');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
