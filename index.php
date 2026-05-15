<?php
// index.php - Página de Login
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        if (login($username, $password)) {
            header('Location: ' . BASE_URL . '/pages/dashboard.php');
            exit;
        }
    }
    $error = 'Usuario o contraseña incorrectos.';
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-box">
                <img class="img" src="<?= BASE_URL ?>/image/logoSena.png" alt="">
            </div>
            <span class="brand">SENA</span>
            <span class="brand-sub">Servicio Nacional de Aprendizaje</span>
        </div>

        <div class="login-title">Sistema de Seguimiento</div>

        <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Usuario</label>
                <div class="input-icon-wrap">
                    <span class="icon">✉</span>
                    <input type="text" id="username" name="username" placeholder="Ingrese su usuario"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username">
                </div>
            </div>
            <div class="form-group" style="margin-top:14px">
                <label for="password">Contraseña</label>
                <div class="input-icon-wrap">
                    <span class="icon">🔒</span>
                    <input type="password" id="password" name="password" placeholder="Ingrese su contraseña" required autocomplete="current-password">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:22px;justify-content:center;padding:13px;font-size:15px;">
                Iniciar Sesión
            </button>
        </form>

        <div class="login-footer">
            SENA &bull; Centro de Comercio y Servicios &bull; <?= date('Y') ?><br>
            <small style="color:#ccc">Usuario por defecto: <strong>admin</strong> / <strong>password</strong></small>
        </div>
    </div>
    
</div>

<div>
        <img src="<?= BASE_URL ?>/image/images.png" alt="">
    </div>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>