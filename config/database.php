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

// ============================================================
// URL BASE DINÁMICA
// Funciona correctamente en localhost y Railway
// ============================================================

if (!defined('BASE_URL')) {

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    define('BASE_URL', $scheme . '://' . $host);
}

// ============================================================
// CONEXIÓN PDO
// ============================================================

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