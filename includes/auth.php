<?php
// ============================================================
// AUTENTICACIÓN Y SESIONES
// Archivo: includes/auth.php
// ============================================================

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── CSRF ─────────────────────────────────────────────────────

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('Acceso denegado: token de seguridad inválido. <a href="javascript:history.back()">Volver</a>');
    }
}

// ── BRUTE FORCE ──────────────────────────────────────────────
// Máx 5 intentos fallidos por IP en 15 minutos

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 min en segundos

function getLoginAttempts(string $ip): array {
    $key = 'login_attempts_' . md5($ip);
    return $_SESSION[$key] ?? ['count' => 0, 'first' => time()];
}

function recordFailedLogin(string $ip): void {
    $key = 'login_attempts_' . md5($ip);
    $data = getLoginAttempts($ip);
    // Reiniciar si ya pasó el tiempo de bloqueo
    if (time() - $data['first'] > LOGIN_LOCKOUT_TIME) {
        $data = ['count' => 0, 'first' => time()];
    }
    $data['count']++;
    $_SESSION[$key] = $data;
}

function clearLoginAttempts(string $ip): void {
    unset($_SESSION['login_attempts_' . md5($ip)]);
}

function isLoginBlocked(string $ip): bool {
    $data = getLoginAttempts($ip);
    if (time() - $data['first'] > LOGIN_LOCKOUT_TIME) {
        clearLoginAttempts($ip);
        return false;
    }
    return $data['count'] >= MAX_LOGIN_ATTEMPTS;
}

function loginAttemptsLeft(string $ip): int {
    $data = getLoginAttempts($ip);
    return max(0, MAX_LOGIN_ATTEMPTS - $data['count']);
}

function loginLockoutSecondsLeft(string $ip): int {
    $data = getLoginAttempts($ip);
    return max(0, LOGIN_LOCKOUT_TIME - (time() - $data['first']));
}

// ── AUTENTICACIÓN ─────────────────────────────────────────────

function isLoggedIn(): bool {
    if (!isset($_SESSION['user_id'])) return false;
    if (time() - ($_SESSION['last_activity'] ?? 0) > SESSION_TIMEOUT) {
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function getCurrentUser(): array {
    return $_SESSION['user'] ?? [];
}

function hasRole(array $roles): bool {
    return in_array($_SESSION['user']['rol'] ?? '', $roles);
}

function login(string $username, string $password): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (isLoginBlocked($ip)) {
        return false;
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE username = ? AND activo = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Regenerar ID de sesión para prevenir session fixation
        session_regenerate_id(true);
        clearLoginAttempts($ip);
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['user']          = $user;
        $_SESSION['last_activity'] = time();
        $db->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?")
           ->execute([$user['id']]);
        return true;
    }

    recordFailedLogin($ip);
    return false;
}

function logout(): void {
    // Verificar CSRF solo si viene por POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!empty($_SESSION['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $token)) {
            // Token inválido — igual cerramos sesión por seguridad
        }
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function sanitize(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

// ── CONTROL DE ACCESO POR FICHA ───────────────────────────────

// ── HELPERS DE ROL ───────────────────────────────────────────

function isAprendiz(): bool {
    return ($_SESSION['user']['rol'] ?? '') === 'Aprendiz';
}

function isInstructor(): bool {
    return ($_SESSION['user']['rol'] ?? '') === 'Instructor';
}

function isGestor(): bool {
    return ($_SESSION['user']['rol'] ?? '') === 'Gestor';
}

function isCoordinadorOrUp(): bool {
    return in_array($_SESSION['user']['rol'] ?? '', ['Coordinador', 'Administrador']);
}

function denyIfAprendiz(): void {
    if (isAprendiz()) {
        header('Location: ' . BASE_URL . '/pages/dashboard.php');
        exit;
    }
}

function denyIfInstructorOrAprendiz(): void {
    if (isAprendiz() || isInstructor()) {
        header('Location: ' . BASE_URL . '/pages/dashboard.php');
        exit;
    }
}

function getFichasPermitidas(PDO $db): array {
    $user = getCurrentUser();
    $rol  = $user['rol'] ?? '';
    if (in_array($rol, ['Administrador', 'Coordinador'])) {
        return []; // Sin restricción
    }
    $userId = (int)($user['id'] ?? 0);
    if ($rol === 'Gestor') {
        $stmt = $db->prepare("SELECT id FROM fichas WHERE gestor_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($rol === 'Instructor') {
        $stmt = $db->prepare("SELECT id FROM instructores WHERE usuario_id = ?");
        $stmt->execute([$userId]);
        $insId = $stmt->fetchColumn();
        if (!$insId) return [-1];
        $stmt = $db->prepare("SELECT ficha_id FROM ficha_instructores WHERE instructor_id = ?");
        $stmt->execute([$insId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $ids ?: [-1];
    }
    if ($rol === 'Aprendiz') {
        // El aprendiz solo ve su propia ficha
        $stmt = $db->prepare("SELECT ficha_id FROM aprendices WHERE usuario_id = ?");
        $stmt->execute([$userId]);
        $fichaId = $stmt->fetchColumn();
        return $fichaId ? [$fichaId] : [-1];
    }
    return [-1];
}

// Retorna el aprendiz_id vinculado al usuario actual (solo rol Aprendiz)
function getAprendizId(PDO $db): int {
    $user = getCurrentUser();
    if (($user['rol'] ?? '') !== 'Aprendiz') return 0;
    $stmt = $db->prepare("SELECT id FROM aprendices WHERE usuario_id = ?");
    $stmt->execute([(int)($user['id'] ?? 0)]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function filtroFichas(PDO $db, string $alias = 'a'): array {
    $fichas = getFichasPermitidas($db);
    if (empty($fichas)) {
        return ['sql' => '1=1', 'params' => []];
    }
    $placeholders = implode(',', array_fill(0, count($fichas), '?'));
    return ['sql' => "{$alias}.ficha_id IN ($placeholders)", 'params' => $fichas];
}
