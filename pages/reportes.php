<?php
// pages/reportes.php - Reportes y estadísticas
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!hasRole(['Administrador','Coordinador'])) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php'); exit;
}
$pageTitle = 'Reportes';
$db = getDB();

// Reporte: Aprendices con más pendientes
$top_aprendices = $db->query("
    SELECT CONCAT(a.nombres,' ',a.apellidos) AS aprendiz, a.documento, f.numero_ficha,
           COUNT(pa.id) AS total_pendientes,
           SUM(CASE WHEN pa.estado='Superado' THEN 1 ELSE 0 END) AS superados,
           SUM(CASE WHEN pa.estado='Pendiente' THEN 1 ELSE 0 END) AS pendientes_act,
           (SELECT COUNT(*) FROM acciones_remediales ar2 WHERE ar2.pendiente_id IN (SELECT id FROM pendientes_aprendices WHERE aprendiz_id=a.id)) AS acciones_tot
    FROM aprendices a
    JOIN fichas f ON f.id=a.ficha_id
    JOIN pendientes_aprendices pa ON pa.aprendiz_id=a.id
    GROUP BY a.id
    ORDER BY total_pendientes DESC
    LIMIT 20
")->fetchAll();

// Reporte: Competencias con más pendientes
$top_competencias = $db->query("
    SELECT c.nombre, COUNT(pa.id) AS total,
           SUM(CASE WHEN pa.estado='Superado' THEN 1 ELSE 0 END) AS superados,
           SUM(CASE WHEN pa.debe_repetir_competencia=1 THEN 1 ELSE 0 END) AS repiten
    FROM competencias c
    JOIN pendientes_aprendices pa ON pa.competencia_id=c.id
    GROUP BY c.id ORDER BY total DESC LIMIT 10
")->fetchAll();

// Reporte: Instructores con acciones
$top_instructores = $db->query("
    SELECT CONCAT(i.nombres,' ',i.apellidos) AS instructor,
           COUNT(ar.id) AS acciones,
           SUM(CASE WHEN ar.resultado='Aprobado' THEN 1 ELSE 0 END) AS aprobados,
           SUM(CASE WHEN ar.novedad_aprobacion=1 THEN 1 ELSE 0 END) AS con_novedad
    FROM instructores i
    LEFT JOIN acciones_remediales ar ON ar.instructor_id=i.id
    GROUP BY i.id ORDER BY acciones DESC
")->fetchAll();

// Tendencia mensual
$tendencia = $db->query("
    SELECT DATE_FORMAT(created_at,'%Y-%m') AS mes, COUNT(*) AS nuevos
    FROM pendientes_aprendices
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY mes ORDER BY mes
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><div class="page-title">Reportes</div><div class="page-subtitle">Estadísticas y análisis del proceso formativo</div></div>
    <button onclick="window.print()" class="btn btn-secondary">🖨 Imprimir</button>
</div>

<!-- TOP APRENDICES -->
<div class="table-card" style="margin-bottom:24px">
    <div class="table-card-header">
        <div class="table-card-title">🔴 Aprendices con Mayor Número de Pendientes</div>
        <small style="color:#888">Ordenados por riesgo descendente</small>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>#</th><th>Aprendiz</th><th>Ficha</th><th>Total Pend.</th><th>Activos</th><th>Superados</th><th>Acciones Rem.</th><th>Riesgo</th></tr></thead>
            <tbody>
            <?php foreach($top_aprendices as $idx=>$r):
                $nivel = $r['total_pendientes']>=3?'high':($r['total_pendientes']>=2?'medium':'low');
                $label = $r['total_pendientes']>=3?'Alto':($r['total_pendientes']>=2?'Medio':'Bajo');
            ?>
            <tr>
                <td><?=$idx+1?></td>
                <td><strong><?=sanitize($r['aprendiz'])?></strong><br><small style="color:#888"><?=sanitize($r['documento'])?></small></td>
                <td><?=sanitize($r['numero_ficha'])?></td>
                <td style="font-weight:700;font-size:16px"><?=$r['total_pendientes']?></td>
                <td style="color:var(--naranja);font-weight:600"><?=$r['pendientes_act']?></td>
                <td style="color:var(--verde);font-weight:600"><?=$r['superados']?></td>
                <td><?=$r['acciones_tot']?></td>
                <td><span class="risk-<?=$nivel?>"><?=$label?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;flex-wrap:wrap">

<!-- TOP COMPETENCIAS -->
<div class="table-card">
    <div class="table-card-header"><div class="table-card-title">📚 Competencias con más Pendientes</div></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Competencia</th><th>Total</th><th>Superados</th><th>Repiten</th></tr></thead>
            <tbody>
            <?php foreach($top_competencias as $c): ?>
            <tr>
                <td style="font-size:12px"><?=sanitize($c['nombre'])?></td>
                <td style="font-weight:700"><?=$c['total']?></td>
                <td style="color:var(--verde)"><?=$c['superados']?></td>
                <td style="color:var(--rojo)"><?=$c['repiten']?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- INSTRUCTORES -->
<div class="table-card">
    <div class="table-card-header"><div class="table-card-title">👨‍🏫 Gestión por Instructor</div></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Instructor</th><th>Acciones</th><th>Aprobados</th><th>Con Novedad</th></tr></thead>
            <tbody>
            <?php foreach($top_instructores as $i): ?>
            <tr>
                <td><?=sanitize($i['instructor'])?></td>
                <td style="font-weight:700"><?=$i['acciones']?></td>
                <td style="color:var(--verde)"><?=$i['aprobados']?></td>
                <td><?=$i['con_novedad']?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<style>
@media(max-width:900px){div[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important}}
@media print{.sidebar,.topbar,.btn{display:none!important}.main-content{margin-left:0!important}}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
