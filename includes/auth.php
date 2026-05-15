<?php
// ============================================================
// AUTENTICACIÓN Y SESIONES
// Archivo: includes/auth.php
// ============================================================

require_once __DIR__ . '/../config/database.php';

// BASE_URL se define dinámicamente en config/database.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE username = ? AND activo = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['user']          = $user;
        $_SESSION['last_activity'] = time();
        $db->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?")
           ->execute([$user['id']]);
        return true;
    }
    return false;
}

function logout(): void {
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function sanitize(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

/**
 * Retorna los IDs de fichas que el usuario actual puede ver.
 * Admin y Coordinador ven todo (array vacío = sin restricción).
 * Gestor: fichas donde gestor_id = su usuario_id
 * Instructor: fichas donde está en ficha_instructores con su instructor_id
 */
function getFichasPermitidas(PDO $db): array {
    $user = getCurrentUser();
    $rol  = $user['rol'] ?? '';
    if (in_array($rol, ['Administrador', 'Coordinador'])) {
        return []; // Sin restricción
    }
    $userId = $user['id'] ?? 0;
    if ($rol === 'Gestor') {
        $stmt = $db->prepare("SELECT id FROM fichas WHERE gestor_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($rol === 'Instructor') {
        // Buscar su instructor_id
        $stmt = $db->prepare("SELECT id FROM instructores WHERE usuario_id = ?");
        $stmt->execute([$userId]);
        $insId = $stmt->fetchColumn();
        if (!$insId) return [-1]; // Sin acceso a nada
        $stmt = $db->prepare("SELECT ficha_id FROM ficha_instructores WHERE instructor_id = ?");
        $stmt->execute([$insId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $ids ?: [-1];
    }
    return [-1]; // Rol desconocido: sin acceso
}

/**
 * Genera cláusula SQL y params para filtrar aprendices por fichas permitidas.
 * $alias = alias de la tabla aprendices en el query (ej: 'a')
 * Retorna ['sql' => '...', 'params' => [...]]
 */
function filtroFichas(PDO $db, string $alias = 'a'): array {
    $fichas = getFichasPermitidas($db);
    if (empty($fichas)) {
        return ['sql' => '1=1', 'params' => []];
    }
    $placeholders = implode(',', array_fill(0, count($fichas), '?'));
    return ['sql' => "{$alias}.ficha_id IN ($placeholders)", 'params' => $fichas];
}
