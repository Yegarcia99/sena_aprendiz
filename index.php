<?php
// index.php - Página de Login
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar CSRF
    $csrfOk = hash_equals(csrfToken(), $_POST['csrf_token'] ?? '');
    if (!$csrfOk) {
        $error = 'Error de seguridad. Recarga la página e intenta de nuevo.';
    } elseif (isLoginBlocked($ip)) {
        $mins = ceil(loginLockoutSecondsLeft($ip) / 60);
        $error = "Demasiados intentos fallidos. Intenta de nuevo en {$mins} minuto(s).";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username && $password) {
            if (login($username, $password)) {
                header('Location: ' . BASE_URL . '/pages/dashboard.php');
                exit;
            }
        }
        $left  = loginAttemptsLeft($ip);
        $error = $left > 0
            ? "Usuario o contraseña incorrectos. Intentos restantes: {$left}."
            : "Demasiados intentos fallidos. Cuenta bloqueada temporalmente.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | SENA Seguimiento Aprendices</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800&family=Nunito+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.">
    <style>
    /* ── Login responsive ─────────────────────────────── */
    .login-page {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #eef3f0;
        padding: 20px;
        box-sizing: border-box;
    }
    .login-card {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 8px 40px rgba(0,0,0,.10);
        padding: 36px 40px;
        width: 100%;
        max-width: 420px;
        box-sizing: border-box;
    }
    @media (max-width: 480px) {
        .login-card { padding: 28px 20px; border-radius: 12px; }
    }
    .login-logo { display: flex; flex-direction: column; align-items: center; margin-bottom: 22px; }
    .login-logo-box { width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; }
    .login-logo-box img { width: 100%; }
    .brand { font-family: 'Nunito', sans-serif; font-weight: 800; font-size: 22px; color: #39A900; letter-spacing: 1px; }
    .brand-sub { font-size: 12px; color: #888; margin-top: 2px; }
    .login-title { font-family: 'Nunito', sans-serif; font-weight: 700; font-size: 18px; text-align: center; margin-bottom: 22px; color: #222; }
    .login-footer { text-align: center; font-size: 12px; color: #aaa; margin-top: 22px; }
    .intentos-bar {
        height: 4px; border-radius: 2px; background: #eee; margin-top: 8px; overflow: hidden;
    }
    .intentos-fill {
        height: 100%; border-radius: 2px; background: #e53935; transition: width .3s;
    }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-box">
                <img class="img" src="<?= BASE_URL ?>/image/logoSena.png" alt="Logo SENA">
            </div>
            <span class="brand">SENA</span>
            <span class="brand-sub">Servicio Nacional de Aprendizaje</span>
        </div>

        <div class="login-title">Sistema de Seguimiento</div>

        <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php
        $bloqueado = isLoginBlocked($ip);
        $intentosUsados = MAX_LOGIN_ATTEMPTS - loginAttemptsLeft($ip);
        ?>

        <form method="POST" action="">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="username">Usuario</label>
                <div class="input-icon-wrap">
                    <span class="icon">✉</span>
                    <input type="text" id="username" name="username"
                           placeholder="Ingrese su usuario"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           required autocomplete="username"
                           <?= $bloqueado ? 'disabled' : '' ?>>
                </div>
            </div>
            <div class="form-group" style="margin-top:14px">
                <label for="password">Contraseña</label>
                <div class="input-icon-wrap">
                    <span class="icon">🔒</span>
                    <input type="password" id="password" name="password"
                           placeholder="Ingrese su contraseña"
                           required autocomplete="current-password"
                           <?= $bloqueado ? 'disabled' : '' ?>>
                </div>
            </div>

            <?php if ($intentosUsados > 0 && !$bloqueado): ?>
            <div class="intentos-bar">
                <div class="intentos-fill" style="width:<?= ($intentosUsados / MAX_LOGIN_ATTEMPTS) * 100 ?>%"></div>
            </div>
            <div style="font-size:11px;color:#e53935;margin-top:4px;text-align:right">
                <?= loginAttemptsLeft($ip) ?> intento(s) restante(s)
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary"
                    style="width:100%;margin-top:20px;justify-content:center;padding:13px;font-size:15px;"
                    <?= $bloqueado ? 'disabled' : '' ?>>
                <?= $bloqueado ? '🔒 Bloqueado temporalmente' : 'Iniciar Sesión' ?>
            </button>
        </form>

        <div class="login-footer">
            SENA &bull; Centro de Comercio y Servicios &bull; <?= date('Y') ?>
        </div>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
