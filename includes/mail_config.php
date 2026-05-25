<?php
// ============================================================
//  CONFIGURACIÓN DE CORREO — SENA Seguimiento de Aprendices
//  Edite solo esta sección antes de subir al servidor
// ============================================================

// ── SMTP ──────────────────────────────────────────────────
// Para Gmail:
//   MAIL_HOST     = 'smtp.gmail.com'
//   MAIL_PORT     = 587
//   MAIL_USERNAME = 'tucuenta@gmail.com'
//   MAIL_PASSWORD = 'contraseña de aplicación' (no la de Gmail normal)
//   MAIL_SECURE   = 'tls'
//   Ver: https://support.google.com/accounts/answer/185833
//
// Para servidor institucional / cPanel / Hosting:
//   MAIL_HOST     = 'mail.tudominio.edu.co'  (o el que dé el hosting)
//   MAIL_PORT     = 587  (o 465 si es SSL)
//   MAIL_USERNAME = 'notificaciones@tudominio.edu.co'
//   MAIL_PASSWORD = 'contraseña del correo'
//   MAIL_SECURE   = 'tls'  (o 'ssl' si el puerto es 465)
// ──────────────────────────────────────────────────────────

define('MAIL_HOST',      'smtp.gmail.com');        // Servidor SMTP
define('MAIL_PORT',       587);                    // Puerto (587=TLS / 465=SSL)
define('MAIL_SECURE',    'tls');                   // 'tls' o 'ssl'
define('MAIL_USERNAME',  'yegarcia9910@gmail.com');    // ← Cambiar
define('MAIL_PASSWORD',  'zttq bpwt kgfp kneu');   // ← Contraseña de aplicación Gmail
define('MAIL_FROM',      'yegarcia9910@gmail.com');    // Remitente (mismo que USERNAME en Gmail)
define('MAIL_FROM_NAME', 'SENA – Seguimiento de Aprendices');

// ── URL pública del sistema ────────────────────────────────
// Cambie esto a la URL real donde quedará el sistema en el servidor
define('MAIL_SISTEMA_URL', 'http://localhost/sena_aprendices');

// ── Correo del coordinador / admin ────────────────────────
// Recibirá copia de cada correo de bienvenida enviado
define('MAIL_COORDINADOR', 'coordinador@sena.edu.co'); // ← Cambiar

// ── Activar / desactivar envío ────────────────────────────
// false = modo prueba (no envía, solo registra en BD)
// true  = envío real por SMTP
define('MAIL_ACTIVO', true);
