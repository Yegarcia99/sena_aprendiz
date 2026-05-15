<?php
// ajax/resultados.php - Retorna resultados de aprendizaje por competencia
require_once __DIR__ . '/../includes/auth.php';
if (!isLoggedIn()) { http_response_code(401); echo '[]'; exit; }
header('Content-Type: application/json');
$db = getDB();
$cid = (int)($_GET['competencia_id'] ?? 0);
if (!$cid) { echo '[]'; exit; }
$stmt = $db->prepare("SELECT id, nombre FROM resultados_aprendizaje WHERE competencia_id=? AND activo=1 ORDER BY nombre");
$stmt->execute([$cid]);
echo json_encode($stmt->fetchAll());
