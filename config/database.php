<?php
// ============================================================
// CONFIGURACIÓN DE BASE DE DATOS
// Archivo: config/database.php
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');         // Cambiar según tu servidor
define('DB_PASS', '');             // Cambiar según tu servidor
define('DB_NAME', 'sena_aprendices');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'SENA - Seguimiento de Aprendices');
define('APP_VERSION', '1.0.0');
define('SESSION_TIMEOUT', 3600); // 1 hora

// ── URL base dinámica — funciona en localhost, XAMPP y cualquier servidor ──
// Usa __DIR__ para encontrar la raíz del proyecto de forma confiable.
// config/database.php está en /config/, por lo que subir un nivel da la raíz.
if (!defined('BASE_URL')) {
    $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Ruta absoluta del sistema de archivos hasta la raíz del proyecto
    $projectRoot = realpath(__DIR__ . '/..');          // /var/www/html/sena_aprendices
    $docRoot     = realpath($_SERVER['DOCUMENT_ROOT']); // /var/www/html
    // Obtener el path relativo desde document_root hasta el proyecto
    $relativePath = str_replace('\\', '/', substr($projectRoot, strlen($docRoot)));
    define('BASE_URL', $scheme . '://' . $host . $relativePath);
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}
