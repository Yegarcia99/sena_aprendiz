<?php
// pages/auditoria.php - Historial general de cambios del sistema
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
requireLogin();

if (!isCoordinadorOrUp()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Auditoria';
$db = getDB();
ensureExpedienteSchema($db);

$modulo = trim($_GET['modulo'] ?? '');
$usuario = trim($_GET['usuario'] ?? '');
$aprendizId = (int)($_GET['aprendiz_id'] ?? 0);
$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');

$where = '1=1';
$params = [];
if ($modulo !== '') {
    $where .= ' AND ac.modulo = ?';
    $params[] = $modulo;
}
if ($usuario !== '') {
    $where .= ' AND (ac.usuario_nombre LIKE ? OR ac.usuario_rol LIKE ?)';
    $params[] = "%$usuario%";
    $params[] = "%$usuario%";
}
if ($aprendizId > 0) {
    $where .= ' AND ac.aprendiz_id = ?';
    $params[] = $aprendizId;
}
if ($desde !== '') {
    $where .= ' AND DATE(ac.created_at) >= ?';
    $params[] = $desde;
}
if ($hasta !== '') {
    $where .= ' AND DATE(ac.created_at) <= ?';
    $params[] = $hasta;
}

$stmt = $db->prepare("
    SELECT ac.*,
           CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre,
           a.documento,
           f.numero_ficha
    FROM auditoria_cambios ac
    LEFT JOIN aprendices a ON a.id = ac.aprendiz_id
    LEFT JOIN fichas f ON f.id = a.ficha_id
    WHERE $where
    ORDER BY ac.created_at DESC, ac.id DESC
    LIMIT 300
");
$stmt->execute($params);
$registros = $stmt->fetchAll();

$modulos = $db->query("SELECT DISTINCT modulo FROM auditoria_cambios ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN);
$aprendices = $db->query("
    SELECT a.id, CONCAT(a.apellidos, ', ', a.nombres) AS nombre, a.documento, f.numero_ficha
    FROM aprendices a
    JOIN fichas f ON f.id=a.ficha_id
    ORDER BY a.apellidos, a.nombres
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Auditoria</div>
        <div class="page-subtitle">Historial general de cambios realizados en el sistema</div>
    </div>
</div>

<form method="GET" class="table-card" style="padding:16px;margin-bottom:18px">
    <div class="form-grid">
        <div class="form-group">
            <label>Modulo</label>
            <select name="modulo">
                <option value="">Todos</option>
                <?php foreach ($modulos as $m): ?>
                    <option value="<?= sanitize($m) ?>" <?= $modulo===$m?'selected':'' ?>><?= sanitize($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Usuario</label>
            <input type="text" name="usuario" value="<?= sanitize($usuario) ?>" placeholder="Nombre o rol">
        </div>
        <div class="form-group">
            <label>Aprendiz</label>
            <select name="aprendiz_id">
                <option value="">Todos</option>
                <?php foreach ($aprendices as $ap): ?>
                    <option value="<?= (int)$ap['id'] ?>" <?= $aprendizId===(int)$ap['id']?'selected':'' ?>>
                        <?= sanitize($ap['nombre']) ?> - <?= sanitize($ap['documento']) ?> / <?= sanitize($ap['numero_ficha']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= sanitize($desde) ?>">
        </div>
        <div class="form-group">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= sanitize($hasta) ?>">
        </div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
        <a href="auditoria.php" class="btn btn-secondary">Limpiar</a>
        <button class="btn btn-primary" type="submit">Filtrar</button>
    </div>
</form>

<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Cambios registrados (<?= count($registros) ?>)</div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Modulo</th>
                    <th>Accion</th>
                    <th>Aprendiz</th>
                    <th>Detalle</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($registros)): ?>
                <tr><td colspan="6"><div class="empty-state"><p>No hay registros con esos filtros.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($registros as $r): ?>
                <tr>
                    <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                    <td><strong><?= sanitize($r['usuario_nombre'] ?: 'Sistema') ?></strong><br><small><?= sanitize($r['usuario_rol'] ?? '') ?></small></td>
                    <td><?= sanitize($r['modulo']) ?></td>
                    <td><span class="badge badge-proceso"><?= sanitize($r['accion']) ?></span></td>
                    <td>
                        <?php if ($r['aprendiz_id']): ?>
                            <strong><?= sanitize($r['aprendiz_nombre']) ?></strong><br>
                            <small><?= sanitize($r['documento']) ?> / Ficha <?= sanitize($r['numero_ficha']) ?></small>
                        <?php else: ?>
                            <small>No aplica</small>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;max-width:460px">
                        <?= nl2br(sanitize($r['descripcion'] ?? '')) ?>
                        <?php if ($r['valor_anterior'] !== null || $r['valor_nuevo'] !== null): ?>
                            <br><small><strong>Antes:</strong> <?= sanitize($r['valor_anterior'] ?? '-') ?> | <strong>Despues:</strong> <?= sanitize($r['valor_nuevo'] ?? '-') ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
