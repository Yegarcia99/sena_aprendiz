<?php
// pages/codigos_barras.php — Analítica gráfica de fichas / grupos
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Analítica de Fichas';
$db = getDB();

// ── Listado de fichas activas ──────────────────────────────
$fichas = $db->query("
    SELECT f.id, f.numero_ficha, p.nombre AS programa, f.jornada,
           COUNT(a.id) AS total_aprendices
    FROM fichas f
    JOIN programas p ON p.id = f.programa_id
    LEFT JOIN aprendices a ON a.ficha_id = f.id AND a.estado = 'Activo'
    WHERE f.activa = 1
    GROUP BY f.id
    ORDER BY f.numero_ficha DESC
")->fetchAll();

$fichaId   = (int)($_GET['ficha_id'] ?? 0);
$fichaInfo = null;
$data      = [];

if ($fichaId) {
    $sf = $db->prepare("
        SELECT f.*, p.nombre AS programa
        FROM fichas f
        JOIN programas p ON p.id = f.programa_id
        WHERE f.id = ?
    ");
    $sf->execute([$fichaId]);
    $fichaInfo = $sf->fetch();

    // 1. Estados de aprendices
    $q = $db->prepare("SELECT estado, COUNT(*) AS total FROM aprendices WHERE ficha_id=? GROUP BY estado");
    $q->execute([$fichaId]);
    $data['estados'] = $q->fetchAll(PDO::FETCH_ASSOC);

    // 2. Pendientes por estado
    $q = $db->prepare("
        SELECT pa.estado, COUNT(*) AS total
        FROM pendientes_aprendices pa
        JOIN aprendices a ON a.id=pa.aprendiz_id
        WHERE a.ficha_id=? GROUP BY pa.estado
    ");
    $q->execute([$fichaId]);
    $data['pendientes_estado'] = $q->fetchAll(PDO::FETCH_ASSOC);

    // 3. Top 10 aprendices con más pendientes activos
    $q = $db->prepare("
        SELECT CONCAT(a.apellidos,', ',a.nombres) AS nombre, COUNT(pa.id) AS total
        FROM aprendices a
        JOIN pendientes_aprendices pa ON pa.aprendiz_id=a.id AND pa.estado NOT IN('Superado')
        WHERE a.ficha_id=?
        GROUP BY a.id ORDER BY total DESC LIMIT 10
    ");
    $q->execute([$fichaId]);
    $data['top_pendientes'] = $q->fetchAll(PDO::FETCH_ASSOC);

    // 4. Pendientes por competencia (top 8)
    $q = $db->prepare("
        SELECT c.nombre AS competencia, COUNT(pa.id) AS total
        FROM pendientes_aprendices pa
        JOIN aprendices a ON a.id=pa.aprendiz_id
        JOIN competencias c ON c.id=pa.competencia_id
        WHERE a.ficha_id=? AND pa.estado NOT IN('Superado')
        GROUP BY c.id ORDER BY total DESC LIMIT 8
    ");
    $q->execute([$fichaId]);
    $data['por_competencia'] = $q->fetchAll(PDO::FETCH_ASSOC);

    // 5. Evolución mensual (últimos 6 meses)
    $q = $db->prepare("
        SELECT DATE_FORMAT(pa.fecha_registro,'%Y-%m') AS mes, COUNT(*) AS total
        FROM pendientes_aprendices pa
        JOIN aprendices a ON a.id=pa.aprendiz_id
        WHERE a.ficha_id=? AND pa.fecha_registro >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY mes ORDER BY mes ASC
    ");
    $q->execute([$fichaId]);
    $data['evolucion'] = $q->fetchAll(PDO::FETCH_ASSOC);

    // 6. Acciones por tipo
    $q = $db->prepare("
        SELECT ar.tipo_accion, COUNT(*) AS total
        FROM acciones_remediales ar
        JOIN pendientes_aprendices pa ON pa.id=ar.pendiente_id
        JOIN aprendices a ON a.id=pa.aprendiz_id
        WHERE a.ficha_id=? GROUP BY ar.tipo_accion ORDER BY total DESC
    ");
    $q->execute([$fichaId]);
    $data['acciones_tipo'] = $q->fetchAll(PDO::FETCH_ASSOC);

    // 7. KPIs
    $k = $db->prepare("
        SELECT
            COUNT(DISTINCT a.id)                                               AS total_aprendices,
            SUM(CASE WHEN ap.pend > 0 THEN 1 ELSE 0 END)                      AS con_pendientes,
            SUM(CASE WHEN ap.pend = 0 OR ap.pend IS NULL THEN 1 ELSE 0 END)   AS sin_pendientes,
            SUM(COALESCE(ap.pend,0))                                           AS total_pendientes_activos,
            SUM(COALESCE(ap.superados,0))                                      AS total_superados
        FROM aprendices a
        LEFT JOIN (
            SELECT aprendiz_id,
                   SUM(CASE WHEN estado NOT IN('Superado') THEN 1 ELSE 0 END) AS pend,
                   SUM(CASE WHEN estado='Superado' THEN 1 ELSE 0 END)         AS superados
            FROM pendientes_aprendices GROUP BY aprendiz_id
        ) ap ON ap.aprendiz_id=a.id
        WHERE a.ficha_id=?
    ");
    $k->execute([$fichaId]);
    $data['kpi'] = $k->fetch(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Analítica de Fichas</div>
        <div class="page-subtitle">Visualización gráfica del estado académico por ficha o grupo</div>
    </div>
    <?php if ($fichaId && $fichaInfo): ?>
    <button class="btn btn-secondary no-print" onclick="window.print()">🖨 Imprimir análisis</button>
    <?php endif; ?>
</div>

<!-- SELECTOR -->
<div class="form-card no-print">
    <div class="form-title">Seleccionar Ficha / Grupo</div>
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="flex:1;min-width:280px">
            <label>Ficha</label>
            <select name="ficha_id" required onchange="this.form.submit()">
                <option value="">— Seleccionar ficha —</option>
                <?php foreach ($fichas as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $f['id']==$fichaId?'selected':'' ?>>
                    <?= sanitize($f['numero_ficha']) ?> — <?= sanitize($f['programa']) ?>
                    (<?= $f['total_aprendices'] ?> aprendices · <?= sanitize($f['jornada']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if (!$fichaId): ?>
<div class="empty-state" style="margin-top:40px">
    <div class="icon">📊</div>
    <p>Selecciona una ficha para ver su análisis gráfico.</p>
</div>
<?php endif; ?>

<?php if ($fichaInfo && $data): ?>
<!-- Encabezado solo impresión -->
<div class="print-header" style="display:none;text-align:center;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #ccc">
    <h1 style="font-family:'Nunito',sans-serif;font-size:22px;margin-bottom:4px">SENA — Analítica de Ficha</h1>
    <p style="font-size:14px;color:#555">Ficha <strong><?= sanitize($fichaInfo['numero_ficha']) ?></strong>
    · <?= sanitize($fichaInfo['programa']) ?> · <?= date('d/m/Y H:i') ?></p>
</div>

<!-- BANNER FICHA -->
<div class="an-banner">
    <div class="an-banner-item"><span class="an-lbl">Ficha</span><strong class="an-val"><?= sanitize($fichaInfo['numero_ficha']) ?></strong></div>
    <div class="an-banner-item"><span class="an-lbl">Programa</span><strong class="an-val"><?= sanitize($fichaInfo['programa']) ?></strong></div>
    <div class="an-banner-item"><span class="an-lbl">Jornada</span><strong class="an-val"><?= sanitize($fichaInfo['jornada']) ?></strong></div>
    <div class="an-banner-item"><span class="an-lbl">Inicio</span><strong class="an-val"><?= date('d/m/Y', strtotime($fichaInfo['fecha_inicio'])) ?></strong></div>
</div>

<!-- KPIs -->
<?php $kpi=$data['kpi']; $total=max(1,(int)$kpi['total_aprendices']); ?>
<div class="stats-grid" style="margin-bottom:26px">
    <div class="stat-card">
        <span class="stat-icon">◉</span>
        <div class="stat-value"><?= (int)$kpi['total_aprendices'] ?></div>
        <div class="stat-label">Total Aprendices</div>
    </div>
    <div class="stat-card">
        <span class="stat-icon">✓</span>
        <div class="stat-value"><?= (int)$kpi['sin_pendientes'] ?></div>
        <div class="stat-label">Al Día</div>
    </div>
    <div class="stat-card warning">
        <span class="stat-icon">◎</span>
        <div class="stat-value"><?= (int)$kpi['con_pendientes'] ?></div>
        <div class="stat-label">Con Pendientes</div>
    </div>
    <div class="stat-card danger">
        <span class="stat-icon">▣</span>
        <div class="stat-value"><?= (int)$kpi['total_pendientes_activos'] ?></div>
        <div class="stat-label">Pendientes Activos</div>
    </div>
    <div class="stat-card info">
        <span class="stat-icon">◆</span>
        <div class="stat-value"><?= (int)$kpi['total_superados'] ?></div>
        <div class="stat-label">Superados</div>
    </div>
    <div class="stat-card">
        <span class="stat-icon">📈</span>
        <div class="stat-value"><?= round(($kpi['sin_pendientes']/$total)*100) ?>%</div>
        <div class="stat-label">Tasa Aprobación</div>
    </div>
</div>

<!-- FILA 1: Donas -->
<div class="an-row">
    <div class="an-card">
        <div class="an-card-title">Estado de Aprendices</div>
        <div class="an-card-sub">Distribución por estado en la ficha</div>
        <div class="an-wrap"><canvas id="cEstados"></canvas></div>
        <div class="an-legend" id="lgEstados"></div>
    </div>
    <div class="an-card">
        <div class="an-card-title">Estado de Pendientes</div>
        <div class="an-card-sub">Distribución por estado de seguimiento</div>
        <div class="an-wrap"><canvas id="cPendEstado"></canvas></div>
        <div class="an-legend" id="lgPendEstado"></div>
    </div>
</div>

<!-- FILA 2: Top aprendices -->
<?php if (!empty($data['top_pendientes'])): ?>
<div class="an-card an-full">
    <div class="an-card-title">Aprendices con Más Pendientes Activos</div>
    <div class="an-card-sub">Quienes requieren mayor atención de seguimiento</div>
    <div class="an-wrap an-tall"><canvas id="cTopAp"></canvas></div>
</div>
<?php endif; ?>

<!-- FILA 3: Competencia + Acciones -->
<div class="an-row">
    <?php if (!empty($data['por_competencia'])): ?>
    <div class="an-card">
        <div class="an-card-title">Pendientes por Competencia</div>
        <div class="an-card-sub">Competencias con más pendientes activos</div>
        <div class="an-wrap an-tall"><canvas id="cComp"></canvas></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($data['acciones_tipo'])): ?>
    <div class="an-card">
        <div class="an-card-title">Acciones Remediales por Tipo</div>
        <div class="an-card-sub">Tipos de acciones realizadas en esta ficha</div>
        <div class="an-wrap"><canvas id="cAcciones"></canvas></div>
        <div class="an-legend" id="lgAcciones"></div>
    </div>
    <?php endif; ?>
</div>

<!-- FILA 4: Evolución mensual -->
<?php if (!empty($data['evolucion'])): ?>
<div class="an-card an-full">
    <div class="an-card-title">Evolución de Pendientes — Últimos 6 meses</div>
    <div class="an-card-sub">Nuevos pendientes registrados mes a mes</div>
    <div class="an-wrap"><canvas id="cEvol"></canvas></div>
</div>
<?php endif; ?>

<style>
.an-banner{display:flex;flex-wrap:wrap;gap:0;background:linear-gradient(135deg,var(--verde-dark) 0%,#1a6b3a 100%);border-radius:var(--radius);padding:20px 28px;margin-bottom:24px;color:#fff}
.an-banner-item{flex:1;min-width:130px;padding:0 18px 0 0}
.an-banner-item:not(:last-child){border-right:1px solid rgba(255,255,255,.15);margin-right:18px}
.an-lbl{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,.6);margin-bottom:4px;font-family:'Nunito',sans-serif}
.an-val{font-size:17px;font-family:'Nunito',sans-serif;font-weight:800;color:#fff}
.an-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;margin-bottom:20px}
.an-card{background:var(--gris-card);border:1.5px solid var(--gris-border);border-radius:var(--radius);padding:22px 24px;margin-bottom:20px;box-shadow:var(--shadow)}
.an-full{width:100%}
.an-card-title{font-family:'Nunito',sans-serif;font-size:16px;font-weight:700;color:var(--negro);margin-bottom:4px}
.an-card-sub{font-size:12px;color:var(--gris-text);margin-bottom:18px}
.an-wrap{position:relative;height:260px}
.an-tall{height:320px}
.an-legend{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:14px;font-size:12px}
.an-legend-item{display:flex;align-items:center;gap:6px}
.an-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
@media print{.print-header{display:block!important}.no-print{display:none!important}.an-card{break-inside:avoid}body{background:white}.main-content{margin-left:0!important}}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script>
const ESTADO_C={'Activo':'#27AE60','Retiro Voluntario':'#e74c3c','Cancelado':'#c0392b','Aplazado':'#f39c12','Egresado':'#2980b9'};
const PEND_C={'Pendiente':'#f39c12','En proceso':'#2980b9','Superado':'#27AE60','Remitido a comité':'#e74c3c'};
const PAL=['#27AE60','#2980b9','#f39c12','#e74c3c','#8e44ad','#16a085','#d35400','#2c3e50'];

function legend(id,labels,colors){
    const el=document.getElementById(id);if(!el)return;
    el.innerHTML=labels.map((l,i)=>`<div class="an-legend-item"><span class="an-dot" style="background:${colors[i]||'#ccc'}"></span>${l}</div>`).join('');
}

// Dona: estados aprendices
(function(){
    const raw=<?= json_encode($data['estados']) ?>;
    if(!raw.length)return;
    const labels=raw.map(r=>r.estado),vals=raw.map(r=>+r.total),colors=labels.map(l=>ESTADO_C[l]||'#aaa');
    new Chart(document.getElementById('cEstados'),{type:'doughnut',data:{labels,datasets:[{data:vals,backgroundColor:colors,borderWidth:2,borderColor:'#fff',hoverOffset:6}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>` ${c.label}: ${c.parsed}`}}}}});
    legend('lgEstados',labels,colors);
})();

// Dona: pendientes por estado
(function(){
    const raw=<?= json_encode($data['pendientes_estado']) ?>;
    if(!raw.length){const el=document.getElementById('cPendEstado');if(el)el.closest('.an-card').insertAdjacentHTML('beforeend','<p style="text-align:center;color:var(--gris-text);padding:10px 0">Sin pendientes registrados.</p>');return;}
    const labels=raw.map(r=>r.estado),vals=raw.map(r=>+r.total),colors=labels.map(l=>PEND_C[l]||'#aaa');
    new Chart(document.getElementById('cPendEstado'),{type:'doughnut',data:{labels,datasets:[{data:vals,backgroundColor:colors,borderWidth:2,borderColor:'#fff',hoverOffset:6}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false}}}});
    legend('lgPendEstado',labels,colors);
})();

// Barras: top aprendices
(function(){
    const el=document.getElementById('cTopAp');if(!el)return;
    const raw=<?= json_encode($data['top_pendientes']) ?>;
    if(!raw.length)return;
    const labels=raw.map(r=>r.nombre.length>32?r.nombre.substring(0,30)+'…':r.nombre);
    const vals=raw.map(r=>+r.total);
    const bg=vals.map((_,i)=>i===0?'#e74c3c':i===1?'#e67e22':'#f39c12');
    new Chart(el,{type:'bar',data:{labels,datasets:[{label:'Pendientes',data:vals,backgroundColor:bg,borderRadius:6,borderSkipped:false}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0},grid:{color:'rgba(0,0,0,.05)'}},y:{grid:{display:false},ticks:{font:{size:12}}}}}});
})();

// Barras: por competencia
(function(){
    const el=document.getElementById('cComp');if(!el)return;
    const raw=<?= json_encode($data['por_competencia']) ?>;
    if(!raw.length)return;
    const labels=raw.map(r=>r.competencia.length>28?r.competencia.substring(0,26)+'…':r.competencia);
    const vals=raw.map(r=>+r.total);
    new Chart(el,{type:'bar',data:{labels,datasets:[{label:'Pendientes',data:vals,backgroundColor:'#27AE60',borderRadius:5,borderSkipped:false}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0},grid:{color:'rgba(0,0,0,.05)'}},y:{grid:{display:false},ticks:{font:{size:11}}}}}});
})();

// Dona: acciones por tipo
(function(){
    const el=document.getElementById('cAcciones');if(!el)return;
    const raw=<?= json_encode($data['acciones_tipo']) ?>;
    if(!raw.length)return;
    const labels=raw.map(r=>r.tipo_accion),vals=raw.map(r=>+r.total),colors=PAL.slice(0,labels.length);
    new Chart(el,{type:'doughnut',data:{labels,datasets:[{data:vals,backgroundColor:colors,borderWidth:2,borderColor:'#fff',hoverOffset:5}]},options:{responsive:true,maintainAspectRatio:false,cutout:'60%',plugins:{legend:{display:false}}}});
    legend('lgAcciones',labels,colors);
})();

// Línea: evolución mensual
(function(){
    const el=document.getElementById('cEvol');if(!el)return;
    const raw=<?= json_encode($data['evolucion']) ?>;
    if(!raw.length)return;
    const M={'01':'Ene','02':'Feb','03':'Mar','04':'Abr','05':'May','06':'Jun','07':'Jul','08':'Ago','09':'Sep','10':'Oct','11':'Nov','12':'Dic'};
    const labels=raw.map(r=>{const p=r.mes.split('-');return(M[p[1]]||p[1])+' '+p[0];});
    const vals=raw.map(r=>+r.total);
    new Chart(el,{type:'line',data:{labels,datasets:[{label:'Pendientes',data:vals,borderColor:'#27AE60',backgroundColor:'rgba(39,174,96,.12)',borderWidth:2.5,pointBackgroundColor:'#27AE60',pointRadius:5,tension:.35,fill:true}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:'rgba(0,0,0,.05)'}},x:{grid:{display:false}}}}});
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
