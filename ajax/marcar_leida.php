<?php
// ajax/marcar_leida.php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');
$db  = getDB();
$id  = (int)($_POST['id']  ?? 0);
$todo = (int)($_POST['todo'] ?? 0);

try {
    if ($todo) {
        $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE estado_envio='Registrada'")->execute();
    } elseif ($id) {
        $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE id=?")->execute([$id]);
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
