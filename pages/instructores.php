<?php
// pages/instructores.php - Con creación automática de usuario
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!hasRole(['Administrador','Coordinador'])) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php'); exit;
}
$pageTitle = 'Instructores';
$db = getDB(); $msg = $err = '';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'nombres'   => trim($_POST['nombres'] ?? ''),
        'apellidos' => trim($_POST['apellidos'] ?? ''),
        'documento' => trim($_POST['documento'] ?? ''),
        'email'     => trim($_POST['email'] ?? ''),
        'telefono'  => trim($_POST['telefono'] ?? ''),
        'tipo'      => $_POST['tipo'] ?? 'Planta',
        'activo'    => isset($_POST['activo']) ? 1 : 0,
    ];
    if (!$data['nombres'] || !$data['apellidos'] || !$data['documento']) {
        $err = 'Campos obligatorios incompletos.';
    } else {
        try {
            $editId = (int)($_POST['edit_id'] ?? 0);
            $db->beginTransaction();
            if ($editId) {
                $db->prepare("UPDATE instructores SET nombres=?,apellidos=?,documento=?,email=?,telefono=?,tipo=?,activo=? WHERE id=?")
                   ->execute([...array_values($data), $editId]);
                // Actualizar datos del usuario vinculado si existe
                $usuarioId = $db->prepare("SELECT usuario_id FROM instructores WHERE id=?");
                $usuarioId->execute([$editId]);
                $uid = $usuarioId->fetchColumn();
                if ($uid) {
                    $db->prepare("UPDATE usuarios SET nombres=?,apellidos=?,email=? WHERE id=?")
                       ->execute([$data['nombres'], $data['apellidos'], $data['email'], $uid]);
                }
                $msg = 'Instructor actualizado.';
            } else {
                // Crear usuario automáticamente
                // Username = documento, contraseña temporal = documento
                $username     = $data['documento'];
                $passTemp     = password_hash($data['documento'], PASSWORD_DEFAULT);
                // Verificar si ya existe username
                $chk = $db->prepare("SELECT id FROM usuarios WHERE username=?");
                $chk->execute([$username]);
                if ($chk->fetchColumn()) {
                    $username = $data['documento'] . '_ins'; // fallback único
                }
                $db->prepare("INSERT INTO usuarios (username,password_hash,nombres,apellidos,email,rol,activo,debe_cambiar_pass) VALUES(?,?,?,?,?,'Instructor',1,1)")
                   ->execute([$username, $passTemp, $data['nombres'], $data['apellidos'], $data['email']]);
                $nuevoUserId = $db->lastInsertId();

                $db->prepare("INSERT INTO instructores (nombres,apellidos,documento,email,telefono,tipo,activo,usuario_id) VALUES(?,?,?,?,?,?,?,?)")
                   ->execute([$data['nombres'],$data['apellidos'],$data['documento'],$data['email'],$data['telefono'],$data['tipo'],$data['activo'],$nuevoUserId]);

                $msg = "Instructor registrado. Usuario creado: <strong>$username</strong> — contraseña temporal: <strong>{$data['documento']}</strong> (deberá cambiarla al ingresar).";
            }
            $db->commit();
            $action = 'list';
        } catch(PDOException $e) {
            $db->rollBack();
            $err = strpos($e->getMessage(),'Duplicate') !== false ? 'Documento o email ya existe.' : 'Error al guardar: ' . $e->getMessage();
        }
    }
}
if ($action === 'delete' && $id) {
    try {
        $db->beginTransaction();
        // Obtener usuario vinculado
        $stmt = $db->prepare("SELECT usuario_id FROM instructores WHERE id=?");
        $stmt->execute([$id]);
        $usuarioVinculado = $stmt->fetchColumn();

        // Desvincular instructor de fichas
        $db->prepare("DELETE FROM ficha_instructores WHERE instructor_id=?")->execute([$id]);

        // Nullificar referencias en pendientes y acciones (no eliminar el historial)
        $db->prepare("UPDATE pendientes_aprendices SET instructor_id=NULL WHERE instructor_id=?")->execute([$id]);
        $db->prepare("UPDATE acciones_remediales SET instructor_id=NULL WHERE instructor_id=?")->execute([$id]);

        // Eliminar instructor
        $db->prepare("DELETE FROM instructores WHERE id=?")->execute([$id]);

        // Eliminar usuario del sistema vinculado si existe
        if ($usuarioVinculado) {
            $db->prepare("DELETE FROM usuarios WHERE id=?")->execute([$usuarioVinculado]);
        }

        $db->commit();
        $msg = 'Instructor eliminado correctamente.';
    } catch (PDOException $e) {
        $db->rollBack();
        $err = 'Error al eliminar el instructor: ' . $e->getMessage();
    }
    $action = 'list';
}

$search = trim($_GET['q'] ?? ''); $page = max(1,(int)($_GET['p']??1)); $limit=20; $offset=($page-1)*$limit;
$where = '1=1'; $params = [];
if ($search) { $where="(i.nombres LIKE ? OR i.apellidos LIKE ? OR i.documento LIKE ?)"; $params=array_fill(0,3,"%$search%"); }
$total = $db->prepare("SELECT COUNT(*) FROM instructores i WHERE $where"); $total->execute($params); $total=$total->fetchColumn(); $pages=ceil($total/$limit);
$stmt = $db->prepare("SELECT i.*, u.username FROM instructores i LEFT JOIN usuarios u ON u.id=i.usuario_id WHERE $where ORDER BY i.apellidos,i.nombres LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$instructores = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><div class="page-title">Instructores</div><div class="page-subtitle">Gestión de instructores — se crea usuario automáticamente al registrar</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-upload" onclick="openModal('modalCargueIns')">↑ Cargue Masivo</button>
        <a href="<?= BASE_URL ?>/ajax/exportar_excel.php?tipo=instructores" class="btn btn-excel">↓ Exportar Excel</a>
        <button class="btn btn-primary" onclick="openModal('modalIns')">+ Nuevo Instructor</button>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= $msg /* Already safe, contains bold HTML */ ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Instructores (<?= $total ?>)</div>
        <form method="GET" style="display:flex;gap:8px">
            <input type="text" name="q" class="search-input" placeholder="Buscar..." value="<?= sanitize($search) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
            <?php if ($search): ?><a href="instructores.php" class="btn btn-sm" style="background:#eee;color:#666">✕</a><?php endif; ?>
        </form>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Documento</th><th>Nombre</th><th>Email</th><th>Tipo</th><th>Usuario Sistema</th><th>Estado</th><th>Opciones</th></tr></thead>
            <tbody>
            <?php if (empty($instructores)): ?>
                <tr><td colspan="7"><div class="empty-state"><div class="icon">◇</div><p>No hay instructores registrados.</p></div></td></tr>
            <?php else: foreach ($instructores as $i): ?>
                <tr>
                    <td><strong><?= sanitize($i['documento']) ?></strong></td>
                    <td><?= sanitize($i['apellidos'].', '.$i['nombres']) ?></td>
                    <td style="font-size:12px"><?= sanitize($i['email'] ?? '—') ?></td>
                    <td><span class="badge badge-proceso"><?= sanitize($i['tipo']) ?></span></td>
                    <td style="font-size:11px">
                        <?php if ($i['username']): ?>
                        <span class="badge badge-activo" style="font-size:10px">👤 <?= sanitize($i['username']) ?></span>
                        <?php else: ?>
                        <span style="color:#bbb;font-size:11px">Sin usuario</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $i['activo']?'badge-activo':'badge-inactivo' ?>"><?= $i['activo']?'Activo':'Inactivo' ?></span></td>
                    <td><div style="display:flex;gap:4px">
                        <button onclick='editIns(<?= json_encode($i, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn btn-sm btn-secondary">✎</button>
                        <button onclick='confirmDelete("instructores.php?action=delete&id=<?=$i['id']?>","<?= sanitize($i['nombres'].' '.$i['apellidos']) ?>")' class="btn btn-sm btn-danger">✕</button>
                    </div></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages>1): ?><div class="pagination"><?php for($i=1;$i<=$pages;$i++): ?><a href="?p=<?=$i?>&q=<?=urlencode($search)?>" class="page-link <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>
</div>

<!-- MODAL INSTRUCTOR -->
<div class="modal-overlay" id="modalIns">
    <div class="modal" style="max-width:560px">
        <div class="modal-header"><div class="modal-title" id="titleIns">Nuevo Instructor</div><button class="modal-close" onclick="closeModal('modalIns')">✕</button></div>
        <form method="POST">
            <input type="hidden" name="edit_id" id="insEditId" value="0">
            <div class="modal-body">
                <div id="infoNuevo" class="alert" style="background:#e8f5e9;border-left:4px solid var(--verde);padding:10px 14px;font-size:12px;margin-bottom:14px">
                    💡 Al registrar un nuevo instructor, se creará automáticamente un usuario con su número de documento como usuario y contraseña temporal.
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Documento *</label><input type="text" name="documento" id="ins_doc" required></div>
                    <div class="form-group"><label>Nombres *</label><input type="text" name="nombres" id="ins_nombres" required></div>
                    <div class="form-group"><label>Apellidos *</label><input type="text" name="apellidos" id="ins_apellidos" required></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" id="ins_email"></div>
                    <div class="form-group"><label>Teléfono</label><input type="text" name="telefono" id="ins_tel"></div>
                    <div class="form-group"><label>Tipo</label><select name="tipo" id="ins_tipo"><option>Planta</option><option>Contrato</option></select></div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:22px">
                        <input type="checkbox" name="activo" id="ins_activo" value="1" checked style="width:auto">
                        <label for="ins_activo" style="text-transform:none;font-size:13px">Activo en el sistema</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalIns')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CARGUE MASIVO -->
<div class="modal-overlay" id="modalCargueIns">
    <div class="modal">
        <div class="modal-header"><div class="modal-title">Cargue Masivo de Instructores</div><button class="modal-close" onclick="closeModal('modalCargueIns')">✕</button></div>
        <div class="modal-body">
            <div class="template-tip">💡 <span>Descarga la plantilla CSV.</span><a href="<?= BASE_URL ?>/ajax/exportar_excel.php?tipo=plantilla_instructores" class="btn btn-sm btn-secondary" style="margin-left:auto">↓ Plantilla</a></div>
            <p style="font-size:13px;color:var(--gris-text);margin-bottom:14px;font-family:'Nunito',sans-serif">Columnas: <strong>documento, nombres, apellidos, email, telefono, tipo</strong></p>
            <div class="upload-zone" id="dzIns" onclick="document.getElementById('csvIns').click()">
                <div class="icon">📂</div><p>Arrastra o haz clic para seleccionar</p><small>Solo .csv</small>
                <input type="file" id="csvIns" accept=".csv,.txt" style="display:none">
            </div>
            <div id="resIns" style="margin-top:14px;display:none"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modalCargueIns')">Cerrar</button>
            <button type="button" class="btn btn-upload" id="btnIns" onclick="procesarCargueIns()" disabled>Procesar</button>
        </div>
    </div>
</div>

<script>
function editIns(d) {
    document.getElementById('titleIns').textContent = 'Editar Instructor';
    document.getElementById('infoNuevo').style.display = 'none';
    document.getElementById('insEditId').value    = d.id;
    document.getElementById('ins_doc').value      = d.documento;
    document.getElementById('ins_nombres').value  = d.nombres;
    document.getElementById('ins_apellidos').value= d.apellidos;
    document.getElementById('ins_email').value    = d.email||'';
    document.getElementById('ins_tel').value      = d.telefono||'';
    document.getElementById('ins_tipo').value     = d.tipo;
    document.getElementById('ins_activo').checked = d.activo==1;
    openModal('modalIns');
}
document.querySelector('[onclick="openModal(\'modalIns\')"]')?.addEventListener('click', () => {
    document.getElementById('titleIns').textContent = 'Nuevo Instructor';
    document.getElementById('infoNuevo').style.display = 'block';
    document.getElementById('insEditId').value = '0';
});
const dzI=document.getElementById('dzIns'), fiI=document.getElementById('csvIns');
dzI.addEventListener('dragover',e=>{e.preventDefault();dzI.classList.add('dragover')});
dzI.addEventListener('dragleave',()=>dzI.classList.remove('dragover'));
dzI.addEventListener('drop',e=>{e.preventDefault();dzI.classList.remove('dragover');fiI.files=e.dataTransfer.files;dzI.querySelector('p').textContent='✅ '+fiI.files[0].name;document.getElementById('btnIns').disabled=false;});
fiI.addEventListener('change',()=>{if(fiI.files[0]){dzI.querySelector('p').textContent='✅ '+fiI.files[0].name;document.getElementById('btnIns').disabled=false;}});
function procesarCargueIns() {
    const fd=new FormData(); fd.append('archivo',fiI.files[0]);
    const btn=document.getElementById('btnIns'); btn.disabled=true; btn.textContent='Procesando...';
    const res=document.getElementById('resIns'); res.style.display='none';
    fetch('<?= BASE_URL ?>/ajax/cargue_masivo.php?tipo=instructores',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            btn.disabled=false; btn.textContent='Procesar'; res.style.display='block';
            if(d.error){res.innerHTML='<div class="alert alert-error">❌ '+d.error+'</div>';}
            else{let h='<div class="alert alert-success">✅ '+d.inserted+' nuevos, '+d.updated+' actualizados.</div>';
                 if(d.errors&&d.errors.length)h+='<div class="alert alert-warning">⚠ '+d.errors.join('<br>')+'</div>';
                 res.innerHTML=h; setTimeout(()=>location.reload(),2500);}
        }).catch(()=>{btn.disabled=false;btn.textContent='Procesar';res.style.display='block';res.innerHTML='<div class="alert alert-error">Error de conexión.</div>';});
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
