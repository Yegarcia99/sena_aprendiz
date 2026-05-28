<?php
// ajax/marcar_leida.php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');
$db   = getDB();
$id   = (int)($_POST['id']  ?? 0);
$todo = (int)($_POST['todo'] ?? 0);
$user = getCurrentUser();
$uid  = (int)($user['id'] ?? 0);
$rol  = $user['rol'] ?? '';
$esAprendiz = ($rol === 'Aprendiz');

try {
    if ($todo) {
        if ($esAprendiz) {
            $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE estado_envio='Registrada' AND usuario_id=?")
               ->execute([$uid]);
        } else {
            $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE estado_envio='Registrada' AND (usuario_id=? OR usuario_id IS NULL)")
               ->execute([$uid]);
        }
    } elseif ($id) {
        if ($esAprendiz) {
            $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE id=? AND usuario_id=?")
               ->execute([$id, $uid]);
        } else {
            $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE id=?")
               ->execute([$id]);
        }
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
