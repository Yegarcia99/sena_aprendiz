<?php
// pages/gestion_usuarios.php — Gestión de usuarios (solo Administrador)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
requireLogin();
denyIfAprendiz(); // Aprendiz no tiene acceso a esta página

// Solo Administrador puede acceder
if (!hasRole(['Administrador'])) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Gestión de Usuarios';
$db  = getDB();
ensureExpedienteSchema($db);
$msg = $err = '';

// ── Cambiar contraseña de un usuario ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    verifyCsrf();

    $uid    = (int)($_POST['usuario_id'] ?? 0);
    $accion = $_POST['accion'];

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
            $stmt = $db->prepare("SELECT nombres, apellidos FROM usuarios WHERE id=?");
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
            registrarAuditoria($db, [
                'modulo' => 'Usuarios',
                'accion' => 'Cambio de contrasena',
                'entidad_tipo' => 'usuario',
                'entidad_id' => $uid,
                'descripcion' => 'Se restablecio la contrasena de la cuenta ' . ($u['nombres'] . ' ' . $u['apellidos']),
                'valor_nuevo' => 'Debe cambiar contrasena en el proximo ingreso',
            ]);
            $msg = '✅ Contraseña actualizada para ' . htmlspecialchars($u['nombres'] . ' ' . $u['apellidos']) . '. El usuario deberá cambiarla al próximo ingreso.';
        }
    } elseif ($accion === 'toggle_activo') {
        $stmt = $db->prepare("SELECT activo FROM usuarios WHERE id=?");
        $stmt->execute([$uid]);
        $actual = $stmt->fetchColumn();
        $nuevo  = $actual ? 0 : 1;
        $db->prepare("UPDATE usuarios SET activo=? WHERE id=?")->execute([$nuevo, $uid]);
        registrarAuditoria($db, [
            'modulo' => 'Usuarios',
            'accion' => $nuevo ? 'Activar usuario' : 'Desactivar usuario',
            'entidad_tipo' => 'usuario',
            'entidad_id' => $uid,
            'descripcion' => 'Cambio de estado de cuenta de usuario',
            'valor_anterior' => $actual ? 'Activo' : 'Inactivo',
            'valor_nuevo' => $nuevo ? 'Activo' : 'Inactivo',
        ]);
        $msg = $nuevo ? '✅ Usuario activado.' : '⚠️ Usuario desactivado.';
    } elseif ($accion === 'eliminar_usuario') {
        if ($uid <= 0) {
            $err = 'Usuario invalido.';
        } elseif ($uid === (int)($_SESSION['user']['id'] ?? 0)) {
            $err = 'No puede eliminar su propia cuenta.';
        } else {
            $stmt = $db->prepare("
                SELECT u.id, u.username, u.nombres, u.apellidos, u.rol,
                       a.id AS aprendiz_id, i.id AS instructor_id
                FROM usuarios u
                LEFT JOIN aprendices a ON a.usuario_id = u.id
                LEFT JOIN instructores i ON i.usuario_id = u.id
                WHERE u.id=?
                LIMIT 1
            ");
            $stmt->execute([$uid]);
            $uEliminar = $stmt->fetch();

            if (!$uEliminar) {
                $err = 'El usuario ya no existe.';
            } elseif (!empty($uEliminar['aprendiz_id']) || !empty($uEliminar['instructor_id'])) {
                $err = 'No se elimino: la cuenta todavia esta vinculada a un aprendiz o instructor.';
            } else {
                $db->prepare("DELETE FROM notificaciones WHERE usuario_id=?")->execute([$uid]);
                registrarAuditoria($db, [
                    'modulo' => 'Usuarios',
                    'accion' => 'Eliminar cuenta huerfana',
                    'entidad_tipo' => 'usuario',
                    'entidad_id' => $uid,
                    'descripcion' => 'Se elimino definitivamente la cuenta ' . $uEliminar['username'],
                    'valor_anterior' => json_encode([
                        'username' => $uEliminar['username'],
                        'nombre' => trim($uEliminar['nombres'] . ' ' . $uEliminar['apellidos']),
                        'rol' => $uEliminar['rol'],
                    ], JSON_UNESCAPED_UNICODE),
                    'valor_nuevo' => 'Eliminado',
                ]);
                $db->prepare("DELETE FROM usuarios WHERE id=?")->execute([$uid]);
                $msg = 'Cuenta eliminada definitivamente: ' . htmlspecialchars($uEliminar['username']);
            }
        }
    }
}

// ── Cargar lista de usuarios con info extra según rol ────────
// Para aprendices: unir con tabla aprendices para mostrar ficha y documento
// Para instructores: unir con tabla instructores para mostrar documento
$usuarios = $db->query("
    SELECT
        u.id, u.username, u.nombres, u.apellidos, u.email, u.rol,
        u.activo, u.debe_cambiar_pass, u.ultimo_acceso, u.created_at, u.foto,
        -- Documento: viene de aprendices o instructores
        COALESCE(a.documento, i.documento)   AS documento,
        a.id AS aprendiz_id,
        i.id AS instructor_id,
        -- Ficha del aprendiz
        f.numero_ficha,
        p.nombre AS programa
    FROM usuarios u
    LEFT JOIN aprendices  a ON a.usuario_id = u.id
    LEFT JOIN instructores i ON i.usuario_id = u.id
    LEFT JOIN fichas f ON f.id = a.ficha_id
    LEFT JOIN programas p ON p.id = f.programa_id
    ORDER BY
        FIELD(u.rol,'Administrador','Coordinador','Gestor','Instructor','Aprendiz'),
        u.apellidos, u.nombres
")->fetchAll();

// Contar por rol para las pestañas
$conteos = ['Todos' => count($usuarios)];
foreach ($usuarios as $u) {
    $rolConteo = $u['rol'] ?: 'Sin rol';
    $conteos[$rolConteo] = ($conteos[$rolConteo] ?? 0) + 1;
    if (empty($u['aprendiz_id']) && empty($u['instructor_id']) && !in_array($u['rol'], ['Administrador','Coordinador','Gestor'], true)) {
        $conteos['Huerfanos'] = ($conteos['Huerfanos'] ?? 0) + 1;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="margin-bottom:20px">
    <div>
        <div class="page-title">👥 Gestión de Usuarios</div>
        <div class="page-subtitle">Consulta y administra las cuentas registradas en el sistema.</div>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-success" style="margin-bottom:16px"><?= $msg ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<!-- Buscador + pestañas de rol -->
<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:16px">
    <input type="text" id="buscarUsuario" placeholder="🔍  Buscar por nombre, usuario, documento o rol…"
        oninput="filtrarUsuarios()"
        style="flex:1;min-width:220px;max-width:380px;padding:9px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-family:'Nunito',sans-serif">

    <div style="display:flex;flex-wrap:wrap;gap:6px" id="pestañas">
        <?php
        $roles = ['Todos', 'Administrador', 'Coordinador', 'Gestor', 'Instructor', 'Aprendiz', 'Sin rol', 'Huerfanos'];
        $rolColors = [
            'Todos'         => '#607d8b',
            'Administrador' => '#1b5e20',
            'Coordinador'   => '#0d47a1',
            'Gestor'        => '#e65100',
            'Instructor'    => '#4a148c',
            'Aprendiz'      => '#006064',
            'Sin rol'       => '#757575',
            'Huerfanos'     => '#b71c1c',
        ];
        foreach ($roles as $r):
            if (!isset($conteos[$r]) && $r !== 'Todos') continue;
            $cnt = $conteos[$r] ?? 0;
            if ($cnt === 0 && $r !== 'Todos') continue;
        ?>
        <button onclick="filtrarPorRol('<?= $r ?>', this)"
            data-rol="<?= $r ?>"
            style="background:<?= $r==='Todos'?'#607d8b':'#eee' ?>;color:<?= $r==='Todos'?'#fff':'#555' ?>;border:none;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Nunito',sans-serif;transition:.2s">
            <?= $r ?> <span style="opacity:.8">(<?= $cnt ?>)</span>
        </button>
        <?php endforeach; ?>
    </div>
</div>

<!-- Tabla de usuarios -->
<div class="table-card" style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px" id="tablaUsuarios">
        <thead>
            <tr>
                <th style="width:46px;padding:12px 8px;background:#f4f8f6"></th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Usuario</th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Nombre Completo</th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Documento</th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Correo</th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Rol / Ficha</th>
                <th style="text-align:center;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Estado</th>
                <th style="text-align:left;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Último Acceso</th>
                <th style="text-align:center;padding:12px 14px;background:#f4f8f6;color:#2d5e44;font-family:'Nunito',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $u):
            $rolMostrar = $u['rol'] ?: 'Sin rol';
            $esHuerfano = empty($u['aprendiz_id']) && empty($u['instructor_id']) && !in_array($u['rol'], ['Administrador','Coordinador','Gestor'], true);
            $fotoUrl = null;
            if (!empty($u['foto'])) {
                $fotoUrl = BASE_URL . '/uploads/fotos_usuarios/' . htmlspecialchars($u['foto'], ENT_QUOTES, 'UTF-8');
            }
            $rolColors2 = [
                'Administrador' => 'background:#e8f5e9;color:#1b5e20',
                'Coordinador'   => 'background:#e3f2fd;color:#0d47a1',
                'Gestor'        => 'background:#fff8e1;color:#e65100',
                'Instructor'    => 'background:#f3e5f5;color:#4a148c',
                'Aprendiz'      => 'background:#e0f7fa;color:#006064',
                'Sin rol'       => 'background:#eeeeee;color:#424242',
            ];
            $rc = $rolColors2[$rolMostrar] ?? 'background:#eee;color:#333';
        ?>
        <tr class="fila-usuario"
            data-rol="<?= htmlspecialchars($rolMostrar) ?>"
            data-huerfano="<?= $esHuerfano ? '1' : '0' ?>"
            data-search="<?= strtolower(htmlspecialchars($u['username'].' '.$u['nombres'].' '.$u['apellidos'].' '.$rolMostrar.' '.($u['documento']??''))) ?>"
            style="border-bottom:1px solid var(--line)">

            <!-- Foto -->
            <td style="padding:8px;text-align:center">
                <?php if ($fotoUrl): ?>
                <img src="<?= $fotoUrl ?>" alt="Foto"
                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid #e0e0e0;display:block;margin:auto">
                <?php else: ?>
                <div style="width:36px;height:36px;border-radius:50%;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:18px;margin:auto;border:2px solid #e8e8e8">👤</div>
                <?php endif; ?>
            </td>

            <!-- Username -->
            <td style="padding:11px 14px;font-weight:700;font-family:'Nunito',sans-serif">
                <?= htmlspecialchars($u['username']) ?>
                <?php if ($u['debe_cambiar_pass']): ?>
                <span title="Debe cambiar contraseña" style="font-size:10px;background:#fff3cd;color:#856404;padding:2px 6px;border-radius:999px;font-weight:700;margin-left:4px">🔐 1er ingreso</span>
                <?php endif; ?>
            </td>

            <!-- Nombre -->
            <td style="padding:11px 14px"><?= htmlspecialchars($u['nombres'] . ' ' . $u['apellidos']) ?></td>

            <!-- Documento -->
            <td style="padding:11px 14px;color:var(--muted);font-size:12px">
                <?= htmlspecialchars($u['documento'] ?? '—') ?>
            </td>

            <!-- Correo -->
            <td style="padding:11px 14px;color:var(--muted);font-size:12px"><?= htmlspecialchars($u['email'] ?: '—') ?></td>

            <!-- Rol + ficha si es aprendiz -->
            <td style="padding:11px 14px">
                <span style="<?= $rc ?>;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700">
                    <?= htmlspecialchars($rolMostrar) ?>
                </span>
                <?php if ($esHuerfano): ?>
                <div style="font-size:11px;color:#b71c1c;margin-top:4px;font-weight:700">Cuenta sin vinculo</div>
                <?php endif; ?>
                <?php if ($u['rol'] === 'Aprendiz' && $u['numero_ficha']): ?>
                <div style="font-size:11px;color:#888;margin-top:4px">
                    📋 <?= htmlspecialchars($u['numero_ficha']) ?>
                    <?php if ($u['programa']): ?>· <span title="<?= htmlspecialchars($u['programa']) ?>"><?= htmlspecialchars(mb_strimwidth($u['programa'], 0, 28, '…')) ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
            </td>

            <!-- Estado -->
            <td style="padding:11px 14px;text-align:center">
                <?php if ($u['activo']): ?>
                <span style="color:#2e7d32;font-weight:700;font-size:12px">● Activo</span>
                <?php else: ?>
                <span style="color:#c62828;font-weight:700;font-size:12px">● Inactivo</span>
                <?php endif; ?>
            </td>

            <!-- Último acceso -->
            <td style="padding:11px 14px;color:var(--muted);font-size:12px">
                <?= $u['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acceso'])) : '—' ?>
            </td>

            <!-- Acciones -->
            <td style="padding:11px 14px;text-align:center;white-space:nowrap">
                <button onclick="abrirModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['nombres'].' '.$u['apellidos'])) ?>')"
                    class="btn btn-secondary"
                    style="font-size:11px;padding:5px 10px;margin-right:4px">
                    🔑 Contraseña
                </button>
                <?php if ($u['id'] != ($_SESSION['user']['id'] ?? 0)): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('¿Confirmar cambio de estado?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="accion" value="toggle_activo">
                    <input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn <?= $u['activo'] ? 'btn-danger' : 'btn-primary' ?>"
                        style="font-size:11px;padding:5px 10px">
                        <?= $u['activo'] ? '🚫 Desactivar' : '✅ Activar' ?>
                    </button>
                </form>
                <?php if ($esHuerfano): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Esta accion elimina definitivamente la cuenta de usuario. ¿Continuar?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="accion" value="eliminar_usuario">
                    <input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-danger"
                        style="font-size:11px;padding:5px 10px;margin-left:4px">
                        Eliminar
                    </button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Mensaje si no hay resultados -->
    <div id="sinResultados" style="display:none;padding:32px;text-align:center;color:#aaa;font-size:14px">
        Sin resultados para esa búsqueda.
    </div>
</div>

<!-- Modal cambio de contraseña -->
<div id="modalPass" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:400px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,.2)">
        <div style="font-family:'Nunito',sans-serif;font-weight:800;font-size:17px;margin-bottom:4px">🔑 Cambiar Contraseña</div>
        <div id="modalNombre" style="font-size:13px;color:var(--muted);margin-bottom:20px"></div>
        <form method="POST" onsubmit="return validarModal()">
            <?= csrfField() ?>
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
// ── Filtro por rol (pestañas) ─────────────────────────────────
let rolActivo = 'Todos';
function filtrarPorRol(rol, btn) {
    rolActivo = rol;
    document.querySelectorAll('#pestañas button').forEach(b => {
        const esActivo = b.dataset.rol === rol;
        const color = {'Todos':'#607d8b','Administrador':'#1b5e20','Coordinador':'#0d47a1','Gestor':'#e65100','Instructor':'#4a148c','Aprendiz':'#006064','Sin rol':'#757575','Huerfanos':'#b71c1c'}[b.dataset.rol] || '#607d8b';
        b.style.background = esActivo ? color : '#eee';
        b.style.color      = esActivo ? '#fff' : '#555';
    });
    filtrarUsuarios();
}

// ── Buscador + filtro rol combinados ─────────────────────────
function filtrarUsuarios() {
    const q   = document.getElementById('buscarUsuario').value.toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.fila-usuario').forEach(row => {
        const matchRol    = rolActivo === 'Todos' || row.dataset.rol === rolActivo || (rolActivo === 'Huerfanos' && row.dataset.huerfano === '1');
        const matchSearch = !q || row.dataset.search.includes(q);
        const show = matchRol && matchSearch;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('sinResultados').style.display = visible === 0 ? 'block' : 'none';
}

// ── Modal contraseña ─────────────────────────────────────────
function abrirModal(id, nombre) {
    document.getElementById('modalUserId').value            = id;
    document.getElementById('modalNombre').textContent      = nombre;
    document.getElementById('modalPassNueva').value         = '';
    document.getElementById('modalPassConfirma').value      = '';
    document.getElementById('modalStrengthFill').style.width = '0';
    document.getElementById('modalStrengthLabel').textContent = '';
    document.getElementById('modalPass').style.display      = 'flex';
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
    if (val.length >= 6)           score++;
    if (val.length >= 10)          score++;
    if (/[A-Z]/.test(val))         score++;
    if (/[0-9]/.test(val))         score++;
    if (/[^A-Za-z0-9]/.test(val))  score++;
    const fill  = document.getElementById('modalStrengthFill');
    const label = document.getElementById('modalStrengthLabel');
    const colors = ['#e53935','#fb8c00','#fdd835','#43a047','#1b5e20'];
    const labels = ['Muy débil','Débil','Regular','Fuerte','Muy fuerte'];
    fill.style.width      = ((score/5)*100) + '%';
    fill.style.background = colors[score-1] || '#eee';
    label.textContent     = val ? (labels[score-1] || '') : '';
    label.style.color     = colors[score-1] || '#aaa';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
