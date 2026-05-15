<?php
// pages/fichas.php - Fichas/Grupos con asignación de Gestor e Instructores
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!hasRole(['Administrador','Coordinador'])) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php'); exit;
}
$pageTitle = 'Fichas / Grupos';
$db = getDB(); $msg = $err = '';

// ── POST: guardar ficha ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ficha'])) {
    $data = [
        'numero_ficha'     => trim($_POST['numero_ficha'] ?? ''),
        'programa_id'      => (int)($_POST['programa_id'] ?? 0),
        'gestor_id'        => ($_POST['gestor_id'] ?? '') !== '' ? (int)$_POST['gestor_id'] : null,
        'fecha_inicio'     => $_POST['fecha_inicio'] ?? '',
        'fecha_fin_lectiva'=> $_POST['fecha_fin_lectiva'] ?: null,
        'jornada'          => $_POST['jornada'] ?? 'Diurna',
        'activa'           => isset($_POST['activa']) ? 1 : 0,
    ];
    $instructoresAsig = array_map('intval', $_POST['instructores_asig'] ?? []);

    if (!$data['numero_ficha'] || !$data['programa_id'] || !$data['fecha_inicio']) {
        $err = 'Campos obligatorios faltantes.';
    } else {
        try {
            $eid = (int)($_POST['edit_id'] ?? 0);
            if ($eid) {
                $db->prepare("UPDATE fichas SET numero_ficha=?,programa_id=?,gestor_id=?,fecha_inicio=?,fecha_fin_lectiva=?,jornada=?,activa=? WHERE id=?")
                   ->execute([$data['numero_ficha'],$data['programa_id'],$data['gestor_id'],
                              $data['fecha_inicio'],$data['fecha_fin_lectiva'],$data['jornada'],$data['activa'],$eid]);
                $fichaId = $eid;
                $msg = 'Ficha actualizada.';
            } else {
                $db->prepare("INSERT INTO fichas (numero_ficha,programa_id,gestor_id,fecha_inicio,fecha_fin_lectiva,jornada,activa) VALUES(?,?,?,?,?,?,?)")
                   ->execute([$data['numero_ficha'],$data['programa_id'],$data['gestor_id'],
                              $data['fecha_inicio'],$data['fecha_fin_lectiva'],$data['jornada'],$data['activa']]);
                $fichaId = $db->lastInsertId();
                $msg = 'Ficha registrada.';
            }
            // Sincronizar instructores
            $db->prepare("DELETE FROM ficha_instructores WHERE ficha_id=?")->execute([$fichaId]);
            foreach ($instructoresAsig as $insId) {
                if ($insId > 0) {
                    $db->prepare("INSERT IGNORE INTO ficha_instructores (ficha_id,instructor_id) VALUES(?,?)")->execute([$fichaId,$insId]);
                }
            }
        } catch(PDOException $e) {
            $err = strpos($e->getMessage(),'Duplicate') !== false ? 'Número de ficha ya existe.' : 'Error al guardar.';
        }
    }
}

$action = $_GET['action'] ?? 'list'; $id = (int)($_GET['id'] ?? 0);
if ($action === 'delete' && $id) {
    verifyCsrf();
    try {
        $db->beginTransaction();
        // Verificar que no tenga aprendices activos
        $numAprendices = $db->prepare("SELECT COUNT(*) FROM aprendices WHERE ficha_id=?");
        $numAprendices->execute([$id]);
        if ($numAprendices->fetchColumn() > 0) {
            $err = 'No se puede eliminar la ficha porque tiene aprendices registrados. Primero elimina o reasigna los aprendices.';
        } else {
            $db->prepare("DELETE FROM ficha_instructores WHERE ficha_id=?")->execute([$id]);
            $db->prepare("DELETE FROM fichas WHERE id=?")->execute([$id]);
            $msg = 'Ficha eliminada correctamente.';
        }
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        $err = 'Error al eliminar la ficha: ' . $e->getMessage();
    }
}

$programas   = $db->query("SELECT * FROM programas WHERE activo=1 ORDER BY nombre")->fetchAll();
$gestores    = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM usuarios WHERE rol IN ('Gestor','Coordinador','Administrador') AND activo=1 ORDER BY nombres")->fetchAll();
$instructores_todos = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM instructores WHERE activo=1 ORDER BY apellidos")->fetchAll();

$fichas = $db->query("
    SELECT f.*, p.nombre AS programa,
           u.nombres AS gestor_nombres, u.apellidos AS gestor_apellidos,
           (SELECT COUNT(*) FROM aprendices a WHERE a.ficha_id=f.id) AS num_aprendices,
           (SELECT GROUP_CONCAT(i.id) FROM ficha_instructores fi JOIN instructores i ON i.id=fi.instructor_id WHERE fi.ficha_id=f.id) AS ins_ids,
           (SELECT GROUP_CONCAT(CONCAT(i.nombres,' ',i.apellidos) SEPARATOR ', ')
            FROM ficha_instructores fi JOIN instructores i ON i.id=fi.instructor_id WHERE fi.ficha_id=f.id) AS ins_nombres
    FROM fichas f
    JOIN programas p ON p.id=f.programa_id
    LEFT JOIN usuarios u ON u.id=f.gestor_id
    ORDER BY f.numero_ficha
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><div class="page-title">Fichas / Grupos</div><div class="page-subtitle">Gestión de fichas con asignación de gestor e instructores</div></div>
    <button class="btn btn-primary" onclick="openModal('modalFicha')">+ Nueva Ficha</button>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div class="table-card">
    <div class="table-wrapper"><table>
        <thead><tr>
            <th>N° Ficha</th><th>Programa</th><th>Gestor</th><th>Instructores</th>
            <th>Jornada</th><th>Inicio</th><th>Aprendices</th><th>Estado</th><th>Opciones</th>
        </tr></thead>
        <tbody>
        <?php if (empty($fichas)): ?>
            <tr><td colspan="9"><div class="empty-state"><p>No hay fichas registradas.</p></div></td></tr>
        <?php else: foreach ($fichas as $f): ?>
        <tr>
            <td><strong><?= sanitize($f['numero_ficha']) ?></strong></td>
            <td style="font-size:12px"><?= sanitize($f['programa']) ?></td>
            <td style="font-size:12px">
                <?php if ($f['gestor_nombres']): ?>
                    <span class="badge badge-proceso" style="font-size:10px"><?= sanitize($f['gestor_nombres'].' '.$f['gestor_apellidos']) ?></span>
                <?php else: ?><span style="color:#bbb">—</span><?php endif; ?>
            </td>
            <td style="font-size:11px;max-width:180px;color:#555">
                <?= $f['ins_nombres'] ? sanitize($f['ins_nombres']) : '<span style="color:#bbb">—</span>' ?>
            </td>
            <td><?= sanitize($f['jornada']) ?></td>
            <td><?= date('d/m/Y', strtotime($f['fecha_inicio'])) ?></td>
            <td style="text-align:center;font-weight:700"><?= $f['num_aprendices'] ?></td>
            <td><span class="badge <?= $f['activa'] ? 'badge-activo' : 'badge-inactivo' ?>"><?= $f['activa'] ? 'Activa' : 'Inactiva' ?></span></td>
            <td><div style="display:flex;gap:4px">
                <a href="aprendices.php?ficha_id=<?= $f['id'] ?>" class="btn btn-sm btn-secondary" title="Ver aprendices">◉</a>
                <button onclick='editFicha(<?= json_encode($f, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn btn-sm btn-secondary">✎</button>
                <button onclick='confirmDelete("fichas.php?action=delete&id=<?= $f['id'] ?>","ficha <?= sanitize($f['numero_ficha']) ?>")' class="btn btn-sm btn-danger">✕</button>
            </div></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>

<!-- MODAL FICHA -->
<div class="modal-overlay" id="modalFicha">
    <div class="modal" style="max-width:680px">
        <div class="modal-header">
            <div class="modal-title" id="titleFicha">Nueva Ficha</div>
            <button class="modal-close" onclick="closeModal('modalFicha')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="_ficha" value="1">
            <input type="hidden" name="edit_id" id="efId" value="0">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>N° Ficha *</label>
                        <input type="text" name="numero_ficha" id="ef_n" required placeholder="Ej: 2345678">
                    </div>
                    <div class="form-group">
                        <label>Programa *</label>
                        <select name="programa_id" id="ef_p" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($programas as $pr): ?>
                            <option value="<?= $pr['id'] ?>"><?= sanitize($pr['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gestor Asignado</label>
                        <select name="gestor_id" id="ef_gestor">
                            <option value="">— Sin gestor —</option>
                            <?php foreach ($gestores as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= sanitize($g['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jornada</label>
                        <select name="jornada" id="ef_j">
                            <option>Diurna</option><option>Nocturna</option><option>Madrugada</option><option>Mixta</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha Inicio *</label>
                        <input type="date" name="fecha_inicio" id="ef_fi" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha Fin Lectiva</label>
                        <input type="date" name="fecha_fin_lectiva" id="ef_ff">
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:20px">
                        <input type="checkbox" name="activa" id="ef_a" value="1" checked style="width:auto">
                        <label for="ef_a" style="text-transform:none">Ficha activa</label>
                    </div>
                </div>

                <!-- Instructores checklist -->
                <div style="margin-top:18px;padding-top:16px;border-top:2px solid var(--gris-border)">
                    <div style="font-weight:800;font-size:14px;margin-bottom:10px;font-family:'Nunito',sans-serif">
                        Instructores Asignados a esta Ficha
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;max-height:200px;overflow-y:auto;padding:4px 2px" id="insCheckList">
                        <?php foreach ($instructores_todos as $ins): ?>
                        <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;padding:3px 0">
                            <input type="checkbox" name="instructores_asig[]" value="<?= $ins['id'] ?>"
                                   class="ins-check" style="width:auto;accent-color:var(--verde)">
                            <?= sanitize($ins['nombre']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (empty($instructores_todos)): ?>
                    <p style="font-size:12px;color:#aaa">No hay instructores activos. <a href="instructores.php">Registrar</a></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalFicha')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Ficha</button>
            </div>
        </form>
    </div>
</div>

<script>
function editFicha(d) {
    document.getElementById('titleFicha').textContent = 'Editar Ficha';
    document.getElementById('efId').value   = d.id;
    document.getElementById('ef_n').value   = d.numero_ficha;
    document.getElementById('ef_p').value   = d.programa_id;
    document.getElementById('ef_gestor').value = d.gestor_id || '';
    document.getElementById('ef_fi').value  = d.fecha_inicio;
    document.getElementById('ef_ff').value  = d.fecha_fin_lectiva || '';
    document.getElementById('ef_j').value   = d.jornada;
    document.getElementById('ef_a').checked = d.activa == 1;

    // Marcar instructores asignados
    const asigIds = d.ins_ids ? d.ins_ids.split(',').map(x => x.trim()) : [];
    document.querySelectorAll('.ins-check').forEach(cb => {
        cb.checked = asigIds.includes(cb.value);
    });
    openModal('modalFicha');
}
// Limpiar al abrir modal nuevo
document.querySelector('[onclick="openModal(\'modalFicha\')"]')?.addEventListener('click', () => {
    document.getElementById('titleFicha').textContent = 'Nueva Ficha';
    document.getElementById('efId').value = '0';
    document.getElementById('ef_n').value = '';
    document.getElementById('ef_p').value = '';
    document.getElementById('ef_gestor').value = '';
    document.getElementById('ef_fi').value = '';
    document.getElementById('ef_ff').value = '';
    document.getElementById('ef_j').value = 'Diurna';
    document.getElementById('ef_a').checked = true;
    document.querySelectorAll('.ins-check').forEach(cb => cb.checked = false);
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
