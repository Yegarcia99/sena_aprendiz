<?php
// pages/aprendices.php - Gestión de Aprendices
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Aprendices';
$db = getDB();
$msg = $err = '';
$ff  = filtroFichas($db, 'a');  // restricción por rol
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'nombres'        => trim($_POST['nombres'] ?? ''),
        'apellidos'      => trim($_POST['apellidos'] ?? ''),
        'documento'      => trim($_POST['documento'] ?? ''),
        'tipo_documento' => $_POST['tipo_documento'] ?? 'CC',
        'email'          => trim($_POST['email'] ?? ''),
        'telefono'       => trim($_POST['telefono'] ?? ''),
        'ficha_id'       => (int)($_POST['ficha_id'] ?? 0),
        'estado'         => $_POST['estado'] ?? 'Activo',
    ];
    if (!$data['nombres'] || !$data['apellidos'] || !$data['documento'] || !$data['ficha_id']) {
        $err = 'Los campos marcados con * son obligatorios.';
    } else {
        try {
            $editId = (int)($_POST['edit_id'] ?? 0);
            if ($editId) {
                $stmt = $db->prepare("UPDATE aprendices SET nombres=?,apellidos=?,documento=?,tipo_documento=?,email=?,telefono=?,ficha_id=?,estado=? WHERE id=?");
                $stmt->execute([...array_values($data), $editId]);
                $msg = 'Aprendiz actualizado correctamente.';
            } else {
                $stmt = $db->prepare("INSERT INTO aprendices (nombres,apellidos,documento,tipo_documento,email,telefono,ficha_id,estado) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute(array_values($data));
                $msg = 'Aprendiz registrado correctamente.';
            }
            $action = 'list';
        } catch (PDOException $e) {
            $err = strpos($e->getMessage(), 'Duplicate') !== false ? 'El número de documento ya existe.' : 'Error al guardar.';
        }
    }
}

if ($action === 'delete' && $id) {
    try {
        $db->beginTransaction();
        // Eliminar registros dependientes antes de eliminar el aprendiz
        $db->prepare("DELETE FROM acciones_remediales WHERE pendiente_id IN (SELECT id FROM pendientes_aprendices WHERE aprendiz_id=?)")->execute([$id]);
        $db->prepare("DELETE FROM pendiente_resultados WHERE pendiente_id IN (SELECT id FROM pendientes_aprendices WHERE aprendiz_id=?)")->execute([$id]);
        $db->prepare("DELETE FROM pendientes_aprendices WHERE aprendiz_id=?")->execute([$id]);
        $db->prepare("DELETE FROM comite_aprendices WHERE aprendiz_id=?")->execute([$id]);
        // Eliminar registros disciplinarios si existen
        if (in_array('disc_hechos', $db->query("SHOW TABLES LIKE 'disc_hechos'")->fetchAll(\PDO::FETCH_COLUMN) ?: [])) {
            $db->prepare("DELETE FROM disc_atenciones WHERE hecho_id IN (SELECT id FROM disc_hechos WHERE aprendiz_id=?)")->execute([$id]);
            $db->prepare("DELETE FROM disc_seguimiento WHERE hecho_id IN (SELECT id FROM disc_hechos WHERE aprendiz_id=?)")->execute([$id]);
            $db->prepare("DELETE FROM disc_hechos WHERE aprendiz_id=?")->execute([$id]);
        }
        $db->prepare("DELETE FROM soportes_expediente WHERE aprendiz_id=?")->execute([$id]);
        $db->prepare("DELETE FROM aprendices WHERE id=?")->execute([$id]);
        $db->commit();
        $msg = 'Aprendiz eliminado correctamente.';
    } catch (PDOException $e) {
        $db->rollBack();
        $err = 'Error al eliminar el aprendiz: ' . $e->getMessage();
    }
    $action = 'list';
}

$fichas = $db->query("SELECT f.id, f.numero_ficha, p.nombre AS programa FROM fichas f JOIN programas p ON p.id=f.programa_id WHERE f.activa=1 ORDER BY f.numero_ficha")->fetchAll();
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;
$where  = $ff['sql']; $params = $ff['params'];
if ($search) {
    $where .= " AND (a.nombres LIKE ? OR a.apellidos LIKE ? OR a.documento LIKE ? OR f.numero_ficha LIKE ?)";
    $params = array_merge($params, array_fill(0, 4, "%$search%"));
}
$total = $db->prepare("SELECT COUNT(*) FROM aprendices a JOIN fichas f ON f.id=a.ficha_id WHERE $where");
$total->execute($params); $total = $total->fetchColumn();
$pages = ceil($total / $limit);
$stmt = $db->prepare("
    SELECT a.*, f.numero_ficha, p.nombre AS programa, COUNT(pa.id) AS total_pendientes
    FROM aprendices a
    JOIN fichas f ON f.id=a.ficha_id
    JOIN programas p ON p.id=f.programa_id
    LEFT JOIN pendientes_aprendices pa ON pa.aprendiz_id=a.id AND pa.estado NOT IN('Superado')
    WHERE $where GROUP BY a.id ORDER BY a.apellidos, a.nombres LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$aprendices = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Aprendices</div>
        <div class="page-subtitle">Registro y seguimiento de aprendices activos</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-upload" onclick="openModal('modalCargue')">↑ Cargue Masivo</button>
        <a href="<?= BASE_URL ?>/ajax/exportar_excel.php?tipo=aprendices" class="btn btn-excel">↓ Exportar Excel</a>
        <a href="<?= BASE_URL ?>/ajax/exportar_excel.php?tipo=plantilla_aprendices" class="btn btn-secondary">↓ Plantilla CSV</a>
        <button class="btn btn-primary" onclick="openModal('modalAprendiz')">+ Nuevo Aprendiz</button>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Lista de Aprendices (<?= $total ?>)</div>
        <form method="GET" style="display:flex;gap:8px">
            <input type="text" name="q" class="search-input" placeholder="Buscar..." value="<?= sanitize($search) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
            <?php if ($search): ?><a href="aprendices.php" class="btn btn-sm" style="background:#eee;color:#666">✕</a><?php endif; ?>
        </form>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Documento</th><th>Nombre</th><th>Ficha</th><th>Programa</th><th>Estado</th><th>Pendientes</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php if (empty($aprendices)): ?>
                <tr><td colspan="7"><div class="empty-state"><div class="icon">◉</div><p>No hay aprendices registrados.</p></div></td></tr>
            <?php else: foreach ($aprendices as $a): ?>
                <tr>
                    <td><small style="color:#999"><?= sanitize($a['tipo_documento']) ?></small><br><strong><?= sanitize($a['documento']) ?></strong></td>
                    <td><?= sanitize($a['apellidos'] . ', ' . $a['nombres']) ?><?php if ($a['email']): ?><br><small style="color:#888"><?= sanitize($a['email']) ?></small><?php endif; ?></td>
                    <td><?= sanitize($a['numero_ficha']) ?></td>
                    <td style="font-size:12px"><?= sanitize($a['programa']) ?></td>
                    <td><span class="badge <?= $a['estado']==='Activo'?'badge-activo':'badge-inactivo' ?>"><?= sanitize($a['estado']) ?></span></td>
                    <td>
                        <?php if ($a['total_pendientes'] > 0): ?>
                        <a href="pendientes.php?aprendiz_id=<?= $a['id'] ?>" style="font-weight:700;color:<?= $a['total_pendientes']>=3?'var(--rojo)':($a['total_pendientes']>=2?'var(--naranja)':'var(--verde-dark)') ?>">
                            <?= $a['total_pendientes'] ?> pendiente<?= $a['total_pendientes']>1?'s':'' ?>
                        </a>
                        <?php else: ?><span style="color:#aaa">—</span><?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="pendientes.php?action=new&aprendiz_id=<?= $a['id'] ?>" class="btn btn-sm btn-secondary" title="Registrar pendiente">+</a>
                            <a href="<?= BASE_URL ?>/pages/codigos_barras.php?ficha_id=<?= $a['ficha_id'] ?>" class="btn btn-sm btn-secondary" title="Ver código de barras">▣</a>
                            <button onclick='editAprendiz(<?= json_encode($a, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn btn-sm btn-secondary">✎</button>
                            <button onclick='confirmDelete("aprendices.php?action=delete&id=<?= $a['id'] ?>","<?= sanitize($a['nombres'].' '.$a['apellidos']) ?>")' class="btn btn-sm btn-danger">✕</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php for ($i=1;$i<=$pages;$i++): ?><a href="?p=<?=$i?>&q=<?=urlencode($search)?>" class="page-link <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL NUEVO/EDITAR APRENDIZ -->
<div class="modal-overlay" id="modalAprendiz">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modalAprendizTitle">Nuevo Aprendiz</div>
            <button class="modal-close" onclick="closeModal('modalAprendiz')">✕</button>
        </div>
        <form method="POST" id="formAprendiz">
            <input type="hidden" name="edit_id" id="editAprendizId" value="0">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group"><label>Tipo Documento *</label><select name="tipo_documento" id="f_tipo_documento"><option>CC</option><option>TI</option><option>CE</option><option>Pasaporte</option></select></div>
                    <div class="form-group"><label>Número Documento *</label><input type="text" name="documento" id="f_documento" required></div>
                    <div class="form-group"><label>Nombres *</label><input type="text" name="nombres" id="f_nombres" required></div>
                    <div class="form-group"><label>Apellidos *</label><input type="text" name="apellidos" id="f_apellidos" required></div>
                    <div class="form-group"><label>Correo Electrónico</label><input type="email" name="email" id="f_email"></div>
                    <div class="form-group"><label>Teléfono</label><input type="text" name="telefono" id="f_telefono"></div>
                    <div class="form-group"><label>Ficha / Grupo *</label>
                        <select name="ficha_id" id="f_ficha_id" required>
                            <option value="">-- Seleccionar ficha --</option>
                            <?php foreach ($fichas as $f): ?><option value="<?= $f['id'] ?>"><?= sanitize($f['numero_ficha']) ?> - <?= sanitize($f['programa']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Estado</label>
                        <select name="estado" id="f_estado"><option>Activo</option><option>Retiro Voluntario</option><option>Cancelado</option><option>Aplazado</option><option>Egresado</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalAprendiz')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Aprendiz</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CARGUE MASIVO -->
<div class="modal-overlay" id="modalCargue">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Cargue Masivo de Aprendices</div>
            <button class="modal-close" onclick="closeModal('modalCargue')">✕</button>
        </div>
        <div class="modal-body">
            <div class="template-tip">
                💡 <span>Descarga primero la plantilla para ver el formato correcto del archivo CSV.</span>
                <a href="<?= BASE_URL ?>/ajax/exportar_excel.php?tipo=plantilla_aprendices" class="btn btn-sm btn-secondary" style="margin-left:auto;flex-shrink:0">↓ Plantilla CSV</a>
            </div>
            <p style="font-size:13px;color:var(--gris-text);margin-bottom:14px;font-family:'Nunito',sans-serif">
                El archivo debe tener columnas: <strong>documento, tipo_documento, nombres, apellidos, email, telefono, ficha, estado</strong><br>
                Los separadores pueden ser <strong>punto y coma (;)</strong> o <strong>coma (,)</strong>.
            </p>
            <div class="upload-zone" id="dropZoneAprendices" onclick="document.getElementById('csvAprendices').click()">
                <div class="icon">📂</div>
                <p>Arrastra el archivo CSV aquí o haz clic para seleccionar</p>
                <small>Solo archivos .csv — máx. 5MB</small>
                <input type="file" id="csvAprendices" accept=".csv,.txt" style="display:none">
            </div>
            <div id="cargueResultado" style="margin-top:14px;display:none"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modalCargue')">Cerrar</button>
            <button type="button" class="btn btn-upload" id="btnProcesar" onclick="procesarCargue('aprendices')" disabled>Procesar Archivo</button>
        </div>
    </div>
</div>

<script>
function editAprendiz(data) {
    document.getElementById('modalAprendizTitle').textContent = 'Editar Aprendiz';
    document.getElementById('editAprendizId').value   = data.id;
    document.getElementById('f_documento').value      = data.documento;
    document.getElementById('f_nombres').value        = data.nombres;
    document.getElementById('f_apellidos').value      = data.apellidos;
    document.getElementById('f_email').value          = data.email || '';
    document.getElementById('f_telefono').value       = data.telefono || '';
    document.getElementById('f_ficha_id').value       = data.ficha_id;
    document.getElementById('f_tipo_documento').value = data.tipo_documento;
    document.getElementById('f_estado').value         = data.estado;
    openModal('modalAprendiz');
}
<?php if ($action==='new'): ?>document.addEventListener('DOMContentLoaded',()=>openModal('modalAprendiz'));<?php endif; ?>

// Drag & drop
const dz = document.getElementById('dropZoneAprendices');
const fi = document.getElementById('csvAprendices');
dz.addEventListener('dragover', e=>{ e.preventDefault(); dz.classList.add('dragover'); });
dz.addEventListener('dragleave', ()=>dz.classList.remove('dragover'));
dz.addEventListener('drop', e=>{ e.preventDefault(); dz.classList.remove('dragover'); fi.files=e.dataTransfer.files; actualizarDropzone(); });
fi.addEventListener('change', actualizarDropzone);
function actualizarDropzone() {
    if (fi.files[0]) {
        dz.querySelector('p').textContent = '✅ ' + fi.files[0].name;
        document.getElementById('btnProcesar').disabled = false;
    }
}
function procesarCargue(tipo) {
    const file = fi.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('archivo', file);
    const btn = document.getElementById('btnProcesar');
    btn.disabled = true; btn.textContent = 'Procesando...';
    const res = document.getElementById('cargueResultado');
    res.style.display = 'none';
    fetch('<?= BASE_URL ?>/ajax/cargue_masivo.php?tipo=' + tipo, { method:'POST', body:fd })
        .then(r=>r.json())
        .then(data=>{
            btn.disabled = false; btn.textContent = 'Procesar Archivo';
            res.style.display = 'block';
            if (data.error) {
                res.innerHTML = '<div class="alert alert-error">❌ ' + data.error + '</div>';
            } else {
                let html = '<div class="alert alert-success">✅ Proceso completado: <strong>' + data.inserted + '</strong> nuevos, <strong>' + data.updated + '</strong> actualizados.</div>';
                if (data.errors && data.errors.length) {
                    html += '<div class="alert alert-warning">⚠ ' + data.errors.length + ' fila(s) con errores:<br><small>' + data.errors.join('<br>') + '</small></div>';
                }
                res.innerHTML = html;
                setTimeout(()=>location.reload(), 2500);
            }
        })
        .catch(()=>{ btn.disabled=false; btn.textContent='Procesar Archivo'; res.style.display='block'; res.innerHTML='<div class="alert alert-error">Error de conexión.</div>'; });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
