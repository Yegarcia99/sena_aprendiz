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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <style>
    /* ── Login background ── */
    .login-page {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        box-sizing: border-box;
        position: relative;
        overflow: hidden;
        background: #e8f5ec;
    }

    /* Círculos decorativos */
    .login-page::before {
        content: "";
        position: fixed;
        width: 480px; height: 480px;
        border-radius: 50%;
        background: rgba(255,255,255,.55);
        top: -140px; left: -120px;
        pointer-events: none;
    }
    .login-page::after {
        content: "";
        position: fixed;
        width: 380px; height: 380px;
        border-radius: 50%;
        background: rgba(255,255,255,.45);
        bottom: -110px; right: -100px;
        pointer-events: none;
    }
    .login-circle-sm {
        position: fixed;
        width: 160px; height: 160px;
        border-radius: 50%;
        background: rgba(255,255,255,.3);
        top: 30px; right: 100px;
        pointer-events: none;
    }

    /* Puntos decorativos */
    .login-dots {
        position: fixed;
        display: grid;
        gap: 9px;
        pointer-events: none;
        z-index: 0;
    }
    .login-dots-tr { top: 24px; right: 28px; grid-template-columns: repeat(5, 5px); }
    .login-dots-bl { bottom: 24px; left: 28px; grid-template-columns: repeat(5, 5px); }
    .login-dot { width: 4px; height: 4px; border-radius: 50%; background: rgba(46,139,87,.22); }

    /* Tarjeta */
    .login-card {
        position: relative;
        z-index: 10;
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 8px 40px rgba(20,80,40,.13), 0 1px 4px rgba(20,80,40,.07);
        padding: 38px 36px 28px;
        width: 100%;
        max-width: 400px;
        box-sizing: border-box;
    }

    @media (max-width: 480px) {
        .login-card { padding: 28px 20px; border-radius: 14px; }
    }

    .login-logo {
        display: flex; flex-direction: column;
        align-items: center; margin-bottom: 20px;
    }
    .login-logo-box {
        width: 68px; height: 68px;
        background: #eaf6ee;
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 12px;
    }
    .login-logo-box img { width: 52px; height: 52px; object-fit: contain; }

    .brand { font-weight: 700; font-size: 22px; color: #1a4a2e; letter-spacing: 2px; }
    .brand-sub { font-size: 11px; color: #7a9e8a; margin-top: 2px; }
    .login-title { font-weight: 600; font-size: 16px; text-align: center; margin-bottom: 24px; color: #1a2e22; }

    /* Inputs */
    .login-card label {
        font-size: 9.5px; font-weight: 700;
        letter-spacing: 1px; text-transform: uppercase;
        color: #6b8f7a;
    }
    .login-card input[type="text"],
    .login-card input[type="password"] {
        border: 1px solid #dde8e1;
        border-radius: 9px;
        font-size: 13px;
        height: 44px;
    }
    .login-card input:focus {
        border-color: #2e8b57;
        box-shadow: 0 0 0 3px rgba(46,139,87,.1);
    }
    .login-card .btn-primary {
        border-radius: 9px;
        font-size: 14px;
        font-weight: 600;
        box-shadow: 0 4px 14px rgba(46,139,87,.25);
        letter-spacing: .2px;
    }

    .login-footer {
        text-align: center; font-size: 11px;
        color: #9ab5a4; margin-top: 22px;
        display: flex; align-items: center;
        justify-content: center; gap: 7px;
    }
    .login-footer-dot {
        width: 3px; height: 3px;
        border-radius: 50%; background: #2e8b57;
        display: inline-block;
    }

    .intentos-bar { height: 4px; border-radius: 2px; background: #eee; margin-top: 8px; overflow: hidden; }
    .intentos-fill { height: 100%; border-radius: 2px; background: #e53935; transition: width .3s; }
    </style>
</head>
<body>
<div class="login-page">
    <!-- Decoraciones -->
    <div class="login-circle-sm"></div>
    <div class="login-dots login-dots-tr">
        <?php for($i=0;$i<25;$i++) echo '<div class="login-dot"></div>'; ?>
    </div>
    <div class="login-dots login-dots-bl">
        <?php for($i=0;$i<25;$i++) echo '<div class="login-dot"></div>'; ?>
    </div>

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
            SENA <span class="login-footer-dot"></span> Regional Caldas <span class="login-footer-dot"></span> <?= date('Y') ?>
        </div>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>