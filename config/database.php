<?php
// ============================================================
// CONFIGURACIÓN DE BASE DE DATOS
// Archivo: config/database.php
// ============================================================

define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'sena_aprendices');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'SENA - Seguimiento de Aprendices');
define('APP_VERSION', '1.0.0');
define('SESSION_TIMEOUT', 3600); // 1 hora

// ── URL base dinámica — funciona en localhost, XAMPP y cualquier servidor ──
if (!defined('BASE_URL')) {

    // Detectar HTTPS correctamente detrás de proxies (Railway, Heroku, Nginx, etc.)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || ($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on'
        || ($_SERVER['SERVER_PORT']            ?? '') === '443';

    $scheme = $isHttps ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Ruta absoluta del proyecto
    $projectRoot = realpath(__DIR__ . '/..');

    // Ruta raíz pública del servidor
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');

    // Ruta relativa (vacía si el proyecto ES la raíz, como en Railway/Docker)
    if ($docRoot && $projectRoot && str_starts_with($projectRoot, $docRoot)) {
        $relativePath = str_replace('\\', '/', substr($projectRoot, strlen($docRoot)));
    } else {
        $relativePath = '';
    }

    define('BASE_URL', $scheme . '://' . $host . $relativePath);
}

function getDB(): PDO {

    static $pdo = null;

    if ($pdo === null) {

        try {

            $dsn = "mysql:host=" . DB_HOST .
                   ";port=" . (getenv('MYSQLPORT') ?: '3306') .
                   ";dbname=" . DB_NAME .
                   ";charset=" . DB_CHARSET;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        } catch (PDOException $e) {

            die(json_encode([
                'error' => 'Error de conexión: ' . $e->getMessage()
            ]));
        }
    }

    return $pdo;
}