<?php
// pages/notificaciones.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
require_once __DIR__ . '/../includes/notificaciones.php';
requireLogin();
$pageTitle = 'Notificaciones';
$db = getDB();
ensureExpedienteSchema($db);
$user = getCurrentUser();
$uid  = (int)($user['id'] ?? 0);
$rol  = $user['rol'] ?? '';
$esAprendiz = ($rol === 'Aprendiz');

// Marcar una como leída — solo las del propio usuario
if (isset($_GET['marcar_id'])) {
    $mid = (int)$_GET['marcar_id'];
    if ($esAprendiz) {
        $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE id=? AND usuario_id=?")
           ->execute([$mid, $uid]);
    } else {
        $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE id=?")
           ->execute([$mid]);
    }
}
// Marcar todas como leídas — solo las del propio usuario
if (isset($_GET['marcar_todas'])) {
    if ($esAprendiz) {
        $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE estado_envio='Registrada' AND usuario_id=?")
           ->execute([$uid]);
    } else {
        $db->prepare("UPDATE notificaciones SET estado_envio='Enviada' WHERE estado_envio='Registrada' AND (usuario_id=? OR usuario_id IS NULL)")
           ->execute([$uid]);
    }
    header('Location: notificaciones.php');
    exit;
}

// Filtro
$filtro = $_GET['filtro'] ?? 'todas';
$where  = '';
if ($filtro === 'nuevas') $where = "AND n.estado_envio='Registrada'";
if ($filtro === 'leidas')  $where = "AND n.estado_envio IN ('Enviada','Fallida','Error')";

// Consulta filtrada por usuario
if ($esAprendiz) {
    $stmt = $db->prepare("
        SELECT n.*, CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre
        FROM notificaciones n
        LEFT JOIN aprendices a ON a.id = n.aprendiz_id
        WHERE n.usuario_id = ? $where
        ORDER BY n.fecha_envio DESC
        LIMIT 100
    ");
    $stmt->execute([$uid]);
} else {
    $stmt = $db->prepare("
        SELECT n.*, CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre
        FROM notificaciones n
        LEFT JOIN aprendices a ON a.id = n.aprendiz_id
        WHERE (n.usuario_id = ? OR n.usuario_id IS NULL) $where
        ORDER BY n.fecha_envio DESC
        LIMIT 100
    ");
    $stmt->execute([$uid]);
}
$lista = $stmt->fetchAll();

$totalNuevas = contarAlertasNuevas($db);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Notificaciones</div>
        <div class="page-subtitle">Alertas del sistema — acciones, planes de mejoramiento y remisiones a comité</div>
    </div>
    <?php if ($totalNuevas > 0): ?>
    <a href="notificaciones.php?marcar_todas=1" class="btn btn-secondary">✓ Marcar todas leídas</a>
    <?php endif; ?>
</div>

<!-- FILTROS -->
<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
    <a href="notificaciones.php?filtro=todas"  class="btn btn-sm <?= $filtro==='todas'  ? 'btn-primary' : 'btn-secondary' ?>">Todas</a>
    <a href="notificaciones.php?filtro=nuevas" class="btn btn-sm <?= $filtro==='nuevas' ? 'btn-primary' : 'btn-secondary' ?>">
        Sin leer <?= $totalNuevas > 0 ? "($totalNuevas)" : '' ?>
    </a>
    <a href="notificaciones.php?filtro=leidas" class="btn btn-sm <?= $filtro==='leidas' ? 'btn-primary' : 'btn-secondary' ?>">Leídas</a>
</div>

<?php if (empty($lista)): ?>
<div class="table-card">
    <div class="empty-state" style="padding:80px 20px">
        <div class="icon">🔔</div>
        <p>Sin notificaciones <?= $filtro !== 'todas' ? 'en esta categoría' : '' ?>.</p>
    </div>
</div>
<?php else: ?>
<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Historial de notificaciones (<?= count($lista) ?>)</div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Asunto</th>
                    <?php if (!$esAprendiz): ?><th>Aprendiz</th><th>Destino</th><?php endif; ?>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lista as $n):
                $iconos = [
                    'Comité'                   => '🔔',
                    'Accion'                   => '⚡',
                    'Plan'                     => '📋',
                    'Expediente'               => '📁',
                    'Sistema'                  => '⚙️',
                    'Bienvenida al sistema'    => '👋',
                    'Notificación coordinador' => '📢',
                ];
                $icono  = $iconos[$n['referencia_tipo']] ?? '📨';
                $esNueva = $n['estado_envio'] === 'Registrada';
            ?>
                <tr style="<?= $esNueva ? 'background:#fffdf0;border-left:3px solid var(--naranja)' : '' ?>">
                    <td style="font-size:18px;text-align:center"><?= $icono ?></td>
                    <td style="font-weight:<?= $esNueva ? '700' : '400' ?>;font-size:12px">
                        <?= sanitize($n['asunto']) ?>
                    </td>
                    <?php if (!$esAprendiz): ?>
                    <td style="font-size:11px"><?= sanitize($n['aprendiz_nombre'] ?? '—') ?></td>
                    <td style="font-size:11px;color:var(--gris-text)"><?= sanitize($n['correo_destino'] ?: '—') ?></td>
                    <?php endif; ?>
                    <td style="font-size:11px;white-space:nowrap"><?= date('d/m/Y H:i', strtotime($n['fecha_envio'])) ?></td>
                    <td>
                        <?php if ($esNueva): ?>
                            <span class="badge badge-pendiente">Nueva</span>
                        <?php elseif (in_array($n['estado_envio'], ['Enviada', 'Error'])): ?>
                            <span class="badge badge-superado">Leída</span>
                        <?php else: ?>
                            <span class="badge badge-proceso"><?= sanitize($n['estado_envio']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($esNueva): ?>
                        <a href="notificaciones.php?marcar_id=<?= $n['id'] ?>&filtro=<?= $filtro ?>" class="btn btn-sm btn-secondary">✓ Leída</a>
                        <?php endif; ?>
                        <?php if (!$esAprendiz && $n['aprendiz_id']): ?>
                        <a href="expediente.php?aprendiz_id=<?= $n['aprendiz_id'] ?>" class="btn btn-sm btn-secondary">📁 Exp.</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($n['mensaje']): ?>
                <tr style="<?= $esNueva ? 'background:#fffdf0' : '' ?>">
                    <td colspan="<?= $esAprendiz ? 4 : 7 ?>" style="padding:4px 14px 10px 44px;font-size:11px;color:var(--gris-text)">
                        <?= nl2br(sanitize(substr($n['mensaje'], 0, 250))) ?>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>