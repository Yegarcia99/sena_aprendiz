<?php
// pages/dashboard.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
requireLogin();

$pageTitle = 'Dashboard';
$db = getDB();
ensureExpedienteSchema($db);

$stats = [];
$stats['total_aprendices']  = $db->query("SELECT COUNT(*) FROM aprendices WHERE estado='Activo'")->fetchColumn();
$stats['total_fichas']      = $db->query("SELECT COUNT(*) FROM fichas WHERE activa=1")->fetchColumn();
$stats['total_pendientes']  = $db->query("SELECT COUNT(*) FROM pendientes_aprendices WHERE estado='Pendiente'")->fetchColumn();
$stats['en_proceso']        = $db->query("SELECT COUNT(*) FROM pendientes_aprendices WHERE estado='En proceso'")->fetchColumn();
$stats['sin_accion']        = $db->query("
    SELECT COUNT(*) FROM pendientes_aprendices pa
    LEFT JOIN acciones_remediales ar ON ar.pendiente_id=pa.id
    WHERE pa.estado NOT IN ('Superado','Cerrado') AND ar.id IS NULL
")->fetchColumn();
$stats['remitidos_comite']  = $db->query("SELECT COUNT(*) FROM comite_aprendices WHERE decision='Pendiente'")->fetchColumn();
$stats['planes_abiertos']   = $db->query("SELECT COUNT(*) FROM planes_mejoramiento WHERE estado='Abierto'")->fetchColumn();
$stats['superados']         = $db->query("SELECT COUNT(*) FROM pendientes_aprendices WHERE estado='Superado'")->fetchColumn();
$stats['acciones_mes']      = $db->query("SELECT COUNT(*) FROM acciones_remediales WHERE MONTH(fecha_accion)=MONTH(NOW()) AND YEAR(fecha_accion)=YEAR(NOW())")->fetchColumn();
$stats['soportes']          = $db->query("SELECT COUNT(*) FROM soportes_expediente")->fetchColumn();

try {
    require_once __DIR__ . '/../includes/disciplinario_schema.php';
    ensureDisciplinarioSchema($db);
    $stats['disc_abiertos']     = $db->query("SELECT COUNT(*) FROM disc_hechos WHERE estado NOT IN ('Cerrado','Remitido a comité')")->fetchColumn();
    $stats['disc_en_atencion']  = $db->query("SELECT COUNT(*) FROM disc_hechos WHERE estado IN ('En atención','Comprometido')")->fetchColumn();
    $stats['disc_reincidentes'] = $db->query("SELECT COUNT(DISTINCT hecho_id) FROM disc_atenciones WHERE resultado='Reincidencia'")->fetchColumn();
    $stats['disc_comite']       = $db->query("SELECT COUNT(*) FROM disc_hechos WHERE estado='Remitido a comité'")->fetchColumn();
    $stats['disc_cerrados']     = $db->query("SELECT COUNT(*) FROM disc_hechos WHERE estado='Cerrado'")->fetchColumn();
    $disc_recientes = $db->query("
        SELECT dh.id, dh.tipo_hecho, dh.estado, dh.fecha_hecho,
               CONCAT(a.nombres,' ',a.apellidos) AS aprendiz, f.numero_ficha
        FROM disc_hechos dh
        JOIN aprendices a ON a.id=dh.aprendiz_id
        JOIN fichas f ON f.id=a.ficha_id
        ORDER BY dh.created_at DESC LIMIT 6
    ")->fetchAll();
} catch (Throwable $e) {
    $stats['disc_abiertos'] = $stats['disc_en_atencion'] = $stats['disc_reincidentes'] = $stats['disc_comite'] = $stats['disc_cerrados'] = 0;
    $disc_recientes = [];
}

$riesgo = $db->query("
    SELECT a.id, a.nombres, a.apellidos, a.documento, f.numero_ficha,
           COUNT(pa.id) AS total_pendientes,
           COALESCE(SUM(ar.cnt),0) AS total_acciones
    FROM aprendices a
    JOIN fichas f ON f.id=a.ficha_id
    JOIN pendientes_aprendices pa ON pa.aprendiz_id=a.id AND pa.estado NOT IN ('Superado','Cerrado')
    LEFT JOIN (SELECT pendiente_id, COUNT(*) AS cnt FROM acciones_remediales GROUP BY pendiente_id) ar ON ar.pendiente_id=pa.id
    WHERE a.estado='Activo'
    GROUP BY a.id HAVING total_pendientes>=1
    ORDER BY total_pendientes DESC, total_acciones ASC LIMIT 8
")->fetchAll();

$ultimas_acciones = $db->query("
    SELECT ar.fecha_accion, ar.tipo_accion, ar.resultado,
           CONCAT(a.nombres,' ',a.apellidos) AS aprendiz, c.nombre AS competencia
    FROM acciones_remediales ar
    JOIN pendientes_aprendices pa ON pa.id=ar.pendiente_id
    JOIN aprendices a ON a.id=pa.aprendiz_id
    JOIN competencias c ON c.id=pa.competencia_id
    ORDER BY ar.created_at DESC LIMIT 6
")->fetchAll();

$expedientes_incompletos = $db->query("
    SELECT a.id, CONCAT(a.nombres,' ',a.apellidos) AS aprendiz, a.documento, f.numero_ficha,
           COUNT(DISTINCT pa.id) AS pendientes, COUNT(DISTINCT ar.id) AS acciones,
           COUNT(DISTINCT pm.id) AS planes, COUNT(DISTINCT se.id) AS soportes
    FROM aprendices a
    JOIN fichas f ON f.id=a.ficha_id
    JOIN pendientes_aprendices pa ON pa.aprendiz_id=a.id AND pa.estado NOT IN ('Superado','Cerrado')
    LEFT JOIN acciones_remediales ar ON ar.pendiente_id=pa.id
    LEFT JOIN planes_mejoramiento pm ON pm.aprendiz_id=a.id
    LEFT JOIN soportes_expediente se ON se.aprendiz_id=a.id
    GROUP BY a.id HAVING acciones=0 OR planes=0 OR soportes=0
    ORDER BY pendientes DESC LIMIT 6
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Dashboard</div>
        <div class="page-subtitle">Resumen general — <?= date('d \d\e F \d\e Y') ?></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/pages/asistente_caso.php" class="btn btn-primary">+ Registrar Caso</a>
        <a href="<?= BASE_URL ?>/pages/reportes.php"       class="btn btn-secondary">Ver Reportes</a>
    </div>
</div>

<!-- TARJETAS GLOBALES -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:28px">
    <div class="stat-card" style="border-top:3px solid var(--verde)">
        <span class="stat-icon" style="background:var(--verde-pale);color:var(--verde-dark)">👥</span>
        <div class="stat-value"><?= $stats['total_aprendices'] ?></div>
        <div class="stat-label">Aprendices activos</div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--azul)">
        <span class="stat-icon" style="background:#e3f2fd;color:#1565c0">📋</span>
        <div class="stat-value"><?= $stats['total_fichas'] ?></div>
        <div class="stat-label">Fichas activas</div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--naranja)">
        <span class="stat-icon" style="background:#fff3e0;color:#e65100">📚</span>
        <div class="stat-value text-warning"><?= $stats['total_pendientes'] + $stats['en_proceso'] ?></div>
        <div class="stat-label">Casos académicos activos</div>
    </div>
    <div class="stat-card" style="border-top:3px solid var(--rojo)">
        <span class="stat-icon" style="background:#ffebee;color:#c62828">⚠️</span>
        <div class="stat-value text-danger"><?= $stats['disc_abiertos'] ?></div>
        <div class="stat-label">Casos disciplinarios abiertos</div>
    </div>
</div>


<!-- ══════════════════════════════════════════════
     SECCIÓN 1 — ACADÉMICO
══════════════════════════════════════════════ -->
<div class="dash-seccion-titulo">
    <div class="dash-seccion-linea dash-linea-verde"></div>
    <span class="dash-seccion-icono">🎓</span>
    <div>
        <div class="dash-seccion-nombre">Seguimiento Académico</div>
        <div class="dash-seccion-desc">Pendientes de competencias, acciones remediales, planes de mejoramiento y comité académico</div>
    </div>
    <a href="<?= BASE_URL ?>/pages/pendientes.php" class="modulo-link" style="margin-left:auto">Ver pendientes →</a>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr));margin-bottom:20px">
    <div class="stat-card stat-mini">
        <div class="stat-value text-warning"><?= $stats['total_pendientes'] ?></div>
        <div class="stat-label">Pendientes</div>
        <a href="<?= BASE_URL ?>/pages/pendientes.php" class="stat-link">Ver →</a>
    </div>
    <div class="stat-card stat-mini">
        <div class="stat-value text-info"><?= $stats['en_proceso'] ?></div>
        <div class="stat-label">En proceso</div>
    </div>
    <div class="stat-card stat-mini" style="border-left:3px solid var(--rojo)">
        <div class="stat-value text-danger"><?= $stats['sin_accion'] ?></div>
        <div class="stat-label">Sin acción aún</div>
        <a href="<?= BASE_URL ?>/pages/acciones.php" class="stat-link">Atender →</a>
    </div>
    <div class="stat-card stat-mini">
        <div class="stat-value text-warning"><?= $stats['planes_abiertos'] ?></div>
        <div class="stat-label">Planes abiertos</div>
        <a href="<?= BASE_URL ?>/pages/expediente.php" class="stat-link">Ver →</a>
    </div>
    <div class="stat-card stat-mini" style="border-left:3px solid var(--rojo)">
        <div class="stat-value text-danger"><?= $stats['remitidos_comite'] ?></div>
        <div class="stat-label">En comité acad.</div>
        <a href="<?= BASE_URL ?>/pages/comite.php" class="stat-link">Ver →</a>
    </div>
    <div class="stat-card stat-mini">
        <div class="stat-value text-success"><?= $stats['superados'] ?></div>
        <div class="stat-label">Superados</div>
    </div>
    <div class="stat-card stat-mini">
        <div class="stat-value"><?= $stats['acciones_mes'] ?></div>
        <div class="stat-label">Acciones este mes</div>
        <a href="<?= BASE_URL ?>/pages/acciones.php" class="stat-link">Ver →</a>
    </div>
    <div class="stat-card stat-mini">
        <div class="stat-value text-info"><?= $stats['soportes'] ?></div>
        <div class="stat-label">Soportes cargados</div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="table-card">
        <div class="table-card-header">
            <div>
                <div class="table-card-title">Aprendices en riesgo académico</div>
                <div class="section-kicker">Mayor cantidad de pendientes y menor intervención registrada.</div>
            </div>
            <a href="<?= BASE_URL ?>/pages/aprendices.php" class="btn btn-secondary btn-sm">Ver todos</a>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Aprendiz</th><th>Ficha</th><th>Pendientes</th><th>Acciones</th><th>Riesgo</th></tr></thead>
                <tbody>
                <?php if (empty($riesgo)): ?>
                    <tr><td colspan="5"><div class="empty-state"><p>✅ Sin aprendices en riesgo.</p></div></td></tr>
                <?php else: foreach ($riesgo as $r):
                    $nivel = $r['total_pendientes']>=3?'high':($r['total_pendientes']>=2?'medium':'low');
                    $label = $r['total_pendientes']>=3?'Alto':($r['total_pendientes']>=2?'Medio':'Bajo');
                ?>
                    <tr>
                        <td><strong><?= sanitize($r['nombres'].' '.$r['apellidos']) ?></strong><br><small style="color:#999"><?= sanitize($r['documento']) ?></small></td>
                        <td><?= sanitize($r['numero_ficha']) ?></td>
                        <td><strong><?= (int)$r['total_pendientes'] ?></strong></td>
                        <td><?= (int)$r['total_acciones'] ?></td>
                        <td><span class="risk-<?= $nivel ?>"><?= $label ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-card">
        <div class="table-card-header">
            <div>
                <div class="table-card-title">Últimas acciones remediales</div>
                <div class="section-kicker">Actividad reciente de instructores y gestores.</div>
            </div>
            <a href="<?= BASE_URL ?>/pages/acciones.php" class="btn btn-secondary btn-sm">Ver todas</a>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Fecha</th><th>Aprendiz</th><th>Tipo</th><th>Resultado</th></tr></thead>
                <tbody>
                <?php if (empty($ultimas_acciones)): ?>
                    <tr><td colspan="4"><div class="empty-state"><p>Sin acciones registradas.</p></div></td></tr>
                <?php else: foreach ($ultimas_acciones as $ac):
                    $cls = match($ac['resultado']) {'Aprobado'=>'badge-superado','No aprobado'=>'badge-pendiente',default=>'badge-proceso'};
                ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($ac['fecha_accion'])) ?></td>
                        <td><?= sanitize($ac['aprendiz']) ?></td>
                        <td style="font-size:12px"><?= sanitize($ac['tipo_accion']) ?></td>
                        <td><span class="badge <?= $cls ?>"><?= sanitize($ac['resultado']) ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($expedientes_incompletos)): ?>
<div class="table-card" style="margin-top:16px">
    <div class="table-card-header">
        <div>
            <div class="table-card-title">⚡ Expedientes que requieren atención</div>
            <div class="section-kicker">Casos activos sin acción, plan o soporte — deben completarse antes de ir a comité.</div>
        </div>
        <a href="<?= BASE_URL ?>/pages/expediente.php" class="btn btn-secondary btn-sm">Consultar</a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Aprendiz</th><th>Ficha</th><th>Pendientes</th><th>Acciones</th><th>Planes</th><th>Soportes</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($expedientes_incompletos as $ex): ?>
                <tr>
                    <td><strong><?= sanitize($ex['aprendiz']) ?></strong><br><small style="color:#999"><?= sanitize($ex['documento']) ?></small></td>
                    <td><?= sanitize($ex['numero_ficha']) ?></td>
                    <td><span class="status-chip"><?= (int)$ex['pendientes'] ?></span></td>
                    <td><?= $ex['acciones']==0 ? '<span style="color:var(--rojo);font-weight:700">0 ⚠</span>' : (int)$ex['acciones'] ?></td>
                    <td><?= $ex['planes']==0   ? '<span style="color:var(--naranja)">0 ⚠</span>'  : (int)$ex['planes']   ?></td>
                    <td><?= $ex['soportes']==0 ? '<span style="color:var(--naranja)">0 ⚠</span>'  : (int)$ex['soportes'] ?></td>
                    <td><a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>/pages/expediente.php?aprendiz_id=<?= $ex['id'] ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════
     SECCIÓN 2 — DISCIPLINARIO
══════════════════════════════════════════════ -->
<div class="dash-seccion-titulo" style="margin-top:36px">
    <div class="dash-seccion-linea dash-linea-naranja"></div>
    <span class="dash-seccion-icono" style="background:#fff3e0;color:#e65100">⚠️</span>
    <div>
        <div class="dash-seccion-nombre">Seguimiento Disciplinario</div>
        <div class="dash-seccion-desc">Hechos disciplinarios, atenciones, compromisos, reincidencias y comité disciplinario</div>
    </div>
    <a href="<?= BASE_URL ?>/pages/disciplinario.php" class="modulo-link" style="margin-left:auto">Ver casos →</a>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr));margin-bottom:20px">
    <div class="stat-card stat-mini" style="border-left:3px solid var(--naranja)">
        <div class="stat-value text-warning"><?= $stats['disc_abiertos'] ?></div>
        <div class="stat-label">Hechos abiertos</div>
        <a href="<?= BASE_URL ?>/pages/disciplinario.php" class="stat-link">Ver →</a>
    </div>
    <div class="stat-card stat-mini">
        <div class="stat-value text-info"><?= $stats['disc_en_atencion'] ?></div>
        <div class="stat-label">En atención</div>
    </div>
    <div class="stat-card stat-mini" style="border-left:3px solid var(--rojo)">
        <div class="stat-value text-danger"><?= $stats['disc_reincidentes'] ?></div>
        <div class="stat-label">Reincidentes</div>
    </div>
    <div class="stat-card stat-mini" style="border-left:3px solid var(--rojo)">
        <div class="stat-value text-danger"><?= $stats['disc_comite'] ?></div>
        <div class="stat-label">En comité disc.</div>
    </div>
    <div class="stat-card stat-mini">
        <div class="stat-value text-success"><?= $stats['disc_cerrados'] ?></div>
        <div class="stat-label">Cerrados</div>
    </div>
</div>

<div class="table-card">
    <div class="table-card-header">
        <div>
            <div class="table-card-title">Casos disciplinarios recientes</div>
            <div class="section-kicker">Últimos hechos registrados en el módulo disciplinario.</div>
        </div>
        <a href="<?= BASE_URL ?>/pages/disciplinario.php" class="btn btn-secondary btn-sm">Ver todos</a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Fecha</th><th>Aprendiz</th><th>Ficha</th><th>Tipo de hecho</th><th>Estado</th></tr></thead>
            <tbody>
            <?php if (empty($disc_recientes)): ?>
                <tr><td colspan="5"><div class="empty-state"><p>✅ Sin casos disciplinarios recientes.</p></div></td></tr>
            <?php else: foreach ($disc_recientes as $d):
                $dCls = match(true) {
                    $d['estado']==='Cerrado'           => 'badge-superado',
                    $d['estado']==='Remitido a comité' => 'badge-pendiente',
                    default                            => 'badge-proceso'
                };
            ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($d['fecha_hecho'])) ?></td>
                    <td><?= sanitize($d['aprendiz']) ?></td>
                    <td><?= sanitize($d['numero_ficha']) ?></td>
                    <td style="font-size:12px"><?= sanitize($d['tipo_hecho']) ?></td>
                    <td><span class="badge <?= $dCls ?>"><?= sanitize($d['estado']) ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
