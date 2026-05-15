<?php
// pages/gestion_usuarios.php — Gestión de usuarios (solo Administrador)
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Solo Administrador puede acceder
if (!hasRole(['Administrador'])) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Gestión de Usuarios';
$db  = getDB();
$msg = $err = '';

// ── Cambiar contraseña de un usuario ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

    $uid      = (int)($_POST['usuario_id'] ?? 0);
    $accion   = $_POST['accion'];

    if ($accion === 'cambiar_pass') {
        $nueva    = $_POST['pass_nueva']    ?? '';
        $confirma = $_POST['pass_confirma'] ?? '';

        if ($uid <= 0) {
            $err = 'Usuario inválido.';
        } elseif (strlen($nueva) < 6) {
            $err = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif ($nueva !== $confirma) {
            $err = 'Las contraseñas no coinciden.';
        } else {
            $hash = password_hash($nueva, PASSWORD_DEFAULT);
            $db->prepare("UPDATE usuarios SET password_hash=?, debe_cambiar_pass=1 WHERE id=?")
               ->execute([$hash, $uid]);
            // Obtener nombre para el mensaje
            $stmt = $db->prepare("SELECT nombres, apellidos FROM usuarios WHERE id=?");
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
            $msg = '✅ Contraseña actualizada para ' . htmlspecialchars($u['nombres'] . ' ' . $u['apellidos']) . '. El usuario deberá cambiarla al próximo ingreso.';
        }
    } elseif ($accion === 'toggle_activo') {
        $stmt = $db->prepare("SELECT activo FROM usuarios WHERE id=?");
        $stmt->execute([$uid]);
        $actual = $stmt->fetchColumn();
        $nuevo  = $actual ? 0 : 1;
        $db->prepare("UPDATE usuarios SET activo=? WHERE id=?")->execute([$nuevo, $uid]);
        $msg = $nuevo ? '✅ Usuario activado.' : '⚠️ Usuario desactivado.';
    }
}

// ── Cargar lista de usuarios ─────────────────────────────────
$usuarios = $db->query("SELECT id, username, nombres, apellidos, email, rol, activo, debe_cambiar_pass, ultimo_acceso, created_at FROM usuarios ORDER BY rol, nombres")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="margin-bottom:20px">
    <div>
        <div class="page-title">👥 Gestión de Usuarios</div>
        <div class="page-subtitle">Consulta y administra las cuentas registradas en el sistema. Solo visible para Administradores.</div>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-success" style="margin-bottom:16px"><?= $msg ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<!-- Buscador rápido -->
<div style="margin-bottom:16px">
    <input type="text" id="buscarUsuario" placeholder="🔍  Buscar por nombre, usuario o rol…"
        oninput="filtrarUsuarios(this.value)"
        style="width:100%;max-width:400px;padding:9px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-family:'Nunito',sans-serif">
</div>

<!-- Tabla de usuarios -->
<div class="table-card" style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px" id="tablaUsuarios">
        <thead>
            <tr>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Usuario</th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Nombre Completo</th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Correo</th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Rol</th>
                <th style="text-align:center;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Estado</th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Último Acceso</th>
                <th style="text-align:center;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
        <tr class="fila-usuario" data-search="<?= strtolower(htmlspecialchars($u['username'].' '.$u['nombres'].' '.$u['apellidos'].' '.$u['rol'])) ?>"
            style="border-bottom:1px solid var(--line)">
            <td style="padding:11px 14px;font-weight:700;font-family:'Nunito',sans-serif">
                <?= htmlspecialchars($u['username']) ?>
                <?php if ($u['debe_cambiar_pass']): ?>
                    <span title="Debe cambiar contraseña" style="font-size:10px;background:#fff3cd;color:#856404;padding:2px 6px;border-radius:999px;font-weight:700;margin-left:4px">🔐 1er ingreso</span>
                <?php endif; ?>
            </td>
            <td style="padding:11px 14px"><?= htmlspecialchars($u['nombres'] . ' ' . $u['apellidos']) ?></td>
            <td style="padding:11px 14px;color:var(--muted);font-size:12px"><?= htmlspecialchars($u['email'] ?: '—') ?></td>
            <td style="padding:11px 14px">
                <?php
                $rolColors = [
                    'Administrador' => 'background:#e8f5e9;color:#1b5e20',
                    'Coordinador'   => 'background:#e3f2fd;color:#0d47a1',
                    'Gestor'        => 'background:#fff8e1;color:#e65100',
                    'Instructor'    => 'background:#f3e5f5;color:#4a148c',
                ];
                $rc = $rolColors[$u['rol']] ?? 'background:#eee;color:#333';
                ?>
                <span style="<?= $rc ?>;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700">
                    <?= htmlspecialchars($u['rol']) ?>
                </span>
            </td>
            <td style="padding:11px 14px;text-align:center">
                <?php if ($u['activo']): ?>
                    <span style="color:#2e7d32;font-weight:700;font-size:12px">● Activo</span>
                <?php else: ?>
                    <span style="color:#c62828;font-weight:700;font-size:12px">● Inactivo</span>
                <?php endif; ?>
            </td>
            <td style="padding:11px 14px;color:var(--muted);font-size:12px">
                <?= $u['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acceso'])) : '—' ?>
            </td>
            <td style="padding:11px 14px;text-align:center;white-space:nowrap">
                <!-- Botón cambiar contraseña -->
                <button onclick="abrirModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['nombres'].' '.$u['apellidos'])) ?>')"
                    class="btn btn-secondary"
                    style="font-size:11px;padding:5px 10px;margin-right:4px">
                    🔑 Contraseña
                </button>
                <!-- Activar / desactivar (no permitir desactivar a sí mismo) -->
                <?php if ($u['id'] != ($_SESSION['user']['id'] ?? 0)): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('¿Confirmar cambio de estado?')">
                    <input type="hidden" name="accion" value="toggle_activo">
                    <input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn <?= $u['activo'] ? 'btn-danger' : 'btn-primary' ?>"
                        style="font-size:11px;padding:5px 10px">
                        <?= $u['activo'] ? '🚫 Desactivar' : '✅ Activar' ?>
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal cambio de contraseña -->
<div id="modalPass" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:400px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,.2)">
        <div style="font-family:'Nunito',sans-serif;font-weight:800;font-size:17px;margin-bottom:4px">🔑 Cambiar Contraseña</div>
        <div id="modalNombre" style="font-size:13px;color:var(--muted);margin-bottom:20px"></div>
        <form method="POST" onsubmit="return validarModal()">
            <input type="hidden" name="accion" value="cambiar_pass">
            <input type="hidden" name="usuario_id" id="modalUserId">
            <div class="form-group" style="margin-bottom:14px">
                <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px">Nueva Contraseña *</label>
                <input type="password" name="pass_nueva" id="modalPassNueva" required minlength="6"
                    placeholder="Mínimo 6 caracteres"
                    style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px;box-sizing:border-box"
                    oninput="checkStrengthModal(this.value)">
                <div style="height:4px;border-radius:2px;margin-top:6px;background:#eee">
                    <div id="modalStrengthFill" style="height:100%;border-radius:2px;width:0;transition:.3s"></div>
                </div>
                <span id="modalStrengthLabel" style="font-size:11px;color:#aaa"></span>
            </div>
            <div class="form-group" style="margin-bottom:20px">
                <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px">Confirmar Contraseña *</label>
                <input type="password" name="pass_confirma" id="modalPassConfirma" required
                    placeholder="Repite la contraseña"
                    style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px;box-sizing:border-box">
            </div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-primary" style="flex:1">Guardar Contraseña</button>
                <button type="button" onclick="cerrarModal()" class="btn btn-secondary" style="flex:1">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modal contraseña ─────────────────────────────────────────
function abrirModal(id, nombre) {
    document.getElementById('modalUserId').value   = id;
    document.getElementById('modalNombre').textContent = nombre;
    document.getElementById('modalPassNueva').value    = '';
    document.getElementById('modalPassConfirma').value = '';
    document.getElementById('modalStrengthFill').style.width     = '0';
    document.getElementById('modalStrengthLabel').textContent    = '';
    document.getElementById('modalPass').style.display = 'flex';
}
function cerrarModal() {
    document.getElementById('modalPass').style.display = 'none';
}
document.getElementById('modalPass').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});
function validarModal() {
    const a = document.getElementById('modalPassNueva').value;
    const b = document.getElementById('modalPassConfirma').value;
    if (a.length < 6) { alert('La contraseña debe tener al menos 6 caracteres.'); return false; }
    if (a !== b)       { alert('Las contraseñas no coinciden.');                   return false; }
    return true;
}
function checkStrengthModal(val) {
    let score = 0;
    if (val.length >= 6)         score++;
    if (val.length >= 10)        score++;
    if (/[A-Z]/.test(val))       score++;
    if (/[0-9]/.test(val))       score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const fill  = document.getElementById('modalStrengthFill');
    const label = document.getElementById('modalStrengthLabel');
    const colors = ['#e53935','#fb8c00','#fdd835','#43a047','#1b5e20'];
    const labels = ['Muy débil','Débil','Regular','Fuerte','Muy fuerte'];
    fill.style.width      = ((score/5)*100) + '%';
    fill.style.background = colors[score-1] || '#eee';
    label.textContent     = val ? (labels[score-1] || '') : '';
    label.style.color     = colors[score-1] || '#aaa';
}

// ── Buscador ─────────────────────────────────────────────────
function filtrarUsuarios(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.fila-usuario').forEach(row => {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
