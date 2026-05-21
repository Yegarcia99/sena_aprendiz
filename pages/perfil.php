<?php
// pages/perfil.php - Cambio de contraseña y datos del usuario
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Mi Perfil';
$db  = getDB();
$msg = $err = '';
$user = getCurrentUser();

// ── Forzar cambio si es primera vez ──────────────────────────
$debeCambiar = false;
$stmt = $db->prepare("SELECT debe_cambiar_pass FROM usuarios WHERE id=?");
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
$debeCambiar = ($row['debe_cambiar_pass'] ?? 0) == 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $actual   = $_POST['pass_actual'] ?? '';
    $nueva    = $_POST['pass_nueva'] ?? '';
    $confirma = $_POST['pass_confirma'] ?? '';

    $stmt = $db->prepare("SELECT password_hash FROM usuarios WHERE id=?");
    $stmt->execute([$user['id']]);
    $hash = $stmt->fetchColumn();

    if (!password_verify($actual, $hash)) {
        $err = 'La contraseña actual es incorrecta.';
    } elseif (strlen($nueva) < 6) {
        $err = 'La nueva contraseña debe tener al menos 6 caracteres.';
    } elseif ($nueva !== $confirma) {
        $err = 'Las contraseñas no coinciden.';
    } else {
        $newHash = password_hash($nueva, PASSWORD_DEFAULT);
        $db->prepare("UPDATE usuarios SET password_hash=?, debe_cambiar_pass=0 WHERE id=?")
           ->execute([$newHash, $user['id']]);
        // Actualizar sesión
        $_SESSION['user']['debe_cambiar_pass'] = 0;
        $msg = '✅ Contraseña actualizada correctamente.';
        $debeCambiar = false;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($debeCambiar): ?>
<div class="alert" style="background:#fff3cd;border-left:4px solid #f0a500;padding:14px 18px;font-size:14px;margin-bottom:20px">
    🔐 <strong>Debes cambiar tu contraseña</strong> antes de continuar. Esta es tu primera vez ingresando al sistema.
</div>
<?php endif; ?>

<div class="page-header">
    <div><div class="page-title">Mi Perfil</div><div class="page-subtitle">Información de tu cuenta y cambio de contraseña</div></div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:900px">

    <!-- Info usuario -->
    <div class="table-card" style="padding:24px">
        <div style="font-weight:800;font-size:15px;margin-bottom:18px;font-family:'Nunito',sans-serif">👤 Información de la Cuenta</div>
        <div style="display:flex;flex-direction:column;gap:12px;font-size:14px">
            <div>
                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px">Usuario</div>
                <div style="font-weight:700"><?= sanitize($user['username'] ?? '') ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px">Nombre completo</div>
                <div><?= sanitize(($user['nombres']??'').' '.($user['apellidos']??'')) ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px">Correo</div>
                <div><?= sanitize($user['email'] ?? '—') ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px">Rol</div>
                <span class="badge badge-activo"><?= sanitize($user['rol'] ?? '') ?></span>
            </div>
            <div>
                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px">Último acceso</div>
                <div style="font-size:12px"><?= $user['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($user['ultimo_acceso'])) : '—' ?></div>
            </div>
        </div>
    </div>

    <!-- Cambio contraseña -->
    <div class="table-card" style="padding:24px">
        <div style="font-weight:800;font-size:15px;margin-bottom:18px;font-family:'Nunito',sans-serif">🔐 Cambiar Contraseña</div>
        <form method="POST">
            <?= csrfField() ?>
            <div style="display:flex;flex-direction:column;gap:14px">
                <div class="form-group" style="margin:0">
                    <label>Contraseña Actual *</label>
                    <input type="password" name="pass_actual" required autocomplete="current-password">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Nueva Contraseña *</label>
                    <input type="password" name="pass_nueva" id="passNueva" required autocomplete="new-password" minlength="6"
                           oninput="checkStrength(this.value)">
                    <div id="strengthBar" style="height:4px;border-radius:2px;margin-top:5px;background:#eee;transition:.3s">
                        <div id="strengthFill" style="height:100%;border-radius:2px;width:0;transition:.3s"></div>
                    </div>
                    <span id="strengthLabel" style="font-size:11px;color:#aaa"></span>
                </div>
                <div class="form-group" style="margin:0">
                    <label>Confirmar Nueva Contraseña *</label>
                    <input type="password" name="pass_confirma" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:6px">Actualizar Contraseña</button>
            </div>
        </form>
    </div>
</div>

<script>
function checkStrength(val) {
    const fill = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 6) score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const pct = (score / 5) * 100;
    fill.style.width = pct + '%';
    const colors = ['#e53935','#fb8c00','#fdd835','#43a047','#1b5e20'];
    const labels = ['Muy débil','Débil','Regular','Fuerte','Muy fuerte'];
    fill.style.background = colors[score-1] || '#eee';
    label.textContent = val ? labels[score-1] || '' : '';
    label.style.color  = colors[score-1] || '#aaa';
}
<?php if ($debeCambiar): ?>
// Bloquear navegación si debe cambiar contraseña
window.addEventListener('beforeunload', (e) => {
    const form = document.querySelector('form');
    if (!form.dataset.submitted) {
        e.preventDefault(); return '';
    }
});
document.querySelector('form').addEventListener('submit', function(){ this.dataset.submitted = true; });
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
