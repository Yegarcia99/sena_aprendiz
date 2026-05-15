<?php
// pages/competencias.php - Competencias y Resultados de Aprendizaje
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
// Solo Coordinador/Administrador accede; Instructor y Aprendiz bloqueados
if (!isCoordinadorOrUp()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}
$pageTitle = 'Competencias';
$db  = getDB();
$msg = $err = '';

$programas      = $db->query("SELECT * FROM programas WHERE activo=1 ORDER BY nombre")->fetchAll();
$filtroPrograma = (int)($_GET['programa_id'] ?? 0);
$search         = trim($_GET['q'] ?? '');

// ── GUARDAR RESULTADO ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_res'])) {
    verifyCsrf();
    $rid    = (int)($_POST['rid']       ?? 0);
    $compId = (int)($_POST['comp_id']   ?? 0);
    $nombre = trim($_POST['nombre_res'] ?? '');
    $codigo = trim($_POST['codigo_res'] ?? '') ?: null;

    if (!$nombre || !$compId) {
        $err = 'El nombre del resultado es obligatorio.';
    } elseif ($rid) {
        $db->prepare("UPDATE resultados_aprendizaje SET nombre=?, codigo=? WHERE id=?")
           ->execute([$nombre, $codigo, $rid]);
        $msg = 'Resultado actualizado.';
    } else {
        $db->prepare("INSERT INTO resultados_aprendizaje (competencia_id, nombre, codigo, activo) VALUES (?,?,?,1)")
           ->execute([$compId, $nombre, $codigo]);
        $msg = 'Resultado de aprendizaje agregado correctamente.';
    }
}

// ── ELIMINAR RESULTADO ────────────────────────────────────
if (isset($_GET['del_res'])) {
    try {
        $db->prepare("DELETE FROM resultados_aprendizaje WHERE id=?")->execute([(int)$_GET['del_res']]);
        $msg = 'Resultado eliminado.';
    } catch (PDOException $e) {
        $err = 'No se puede eliminar: el resultado está siendo usado en pendientes de aprendices.';
    }
}

// ── GUARDAR / ELIMINAR COMPETENCIA ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['_res'])) {
    verifyCsrf();
    $accion = $_POST['_accion'] ?? 'guardar';
    $cid    = (int)($_POST['comp_id'] ?? 0);

    if ($accion === 'eliminar') {
        try {
            $db->prepare("DELETE FROM competencias WHERE id=?")->execute([$cid]);
            $msg = 'Competencia eliminada.';
        } catch (PDOException $e) {
            $err = 'No se puede eliminar: tiene resultados o pendientes asociados.';
        }
    } else {
        $data = [
            'nombre'      => trim($_POST['nombre']       ?? ''),
            'codigo'      => trim($_POST['codigo']       ?? '') ?: null,
            'programa_id' => (int)($_POST['programa_id'] ?? 0),
            'trimestre'   => (int)($_POST['trimestre']   ?? 1),
            'horas'       => (int)($_POST['horas']       ?? 0),
            'activa'      => isset($_POST['activa']) ? 1 : 0,
        ];
        if (!$data['nombre'] || !$data['programa_id']) {
            $err = 'Nombre y programa son obligatorios.';
        } elseif ($cid) {
            $db->prepare("UPDATE competencias SET nombre=?,codigo=?,programa_id=?,trimestre=?,horas=?,activa=? WHERE id=?")
               ->execute([...array_values($data), $cid]);
            $msg = 'Competencia actualizada.';
        } else {
            $db->prepare("INSERT INTO competencias (nombre,codigo,programa_id,trimestre,horas,activa) VALUES(?,?,?,?,?,?)")
               ->execute(array_values($data));
            $msg = 'Competencia registrada correctamente.';
        }
    }
}

// ── COMPETENCIA SELECCIONADA ──────────────────────────────
$compActiva = (int)($_GET['comp'] ?? 0);
$compInfo   = null;
$resultados = [];
if ($compActiva) {
    $s = $db->prepare("SELECT c.*, p.nombre AS programa_nombre FROM competencias c JOIN programas p ON p.id=c.programa_id WHERE c.id=?");
    $s->execute([$compActiva]);
    $compInfo = $s->fetch();
    $s2 = $db->prepare("SELECT * FROM resultados_aprendizaje WHERE competencia_id=? ORDER BY nombre");
    $s2->execute([$compActiva]);
    $resultados = $s2->fetchAll();
}

// ── LISTADO COMPETENCIAS ──────────────────────────────────
$where = '1=1'; $params = [];
if ($filtroPrograma) { $where .= ' AND c.programa_id=?'; $params[] = $filtroPrograma; }
if ($search) { $where .= ' AND (c.nombre LIKE ? OR c.codigo LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$comps = $db->prepare("
    SELECT c.*, p.nombre AS programa_nombre,
           COUNT(DISTINCT ra.id) AS n_resultados,
           COUNT(DISTINCT pa.id) AS n_pendientes
    FROM competencias c
    JOIN programas p ON p.id=c.programa_id
    LEFT JOIN resultados_aprendizaje ra ON ra.competencia_id=c.id
    LEFT JOIN pendientes_aprendices  pa ON pa.competencia_id=c.id
    WHERE $where GROUP BY c.id ORDER BY p.nombre, c.trimestre, c.nombre
");
$comps->execute($params);
$comps = $comps->fetchAll();

$urlBase = 'competencias.php?' . http_build_query(array_filter(['programa_id'=>$filtroPrograma,'q'=>$search]));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="page-title">Competencias</div>
        <div class="page-subtitle">Gestión de competencias y resultados de aprendizaje por programa</div>
    </div>
    <button class="btn btn-primary" onclick="resetComp();openModal('modalComp')">+ Nueva Competencia</button>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<!-- FILTROS -->
<form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px">
    <div>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--gris-text);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;font-family:'Nunito',sans-serif">Programa</label>
        <select name="programa_id" class="search-input" style="min-width:200px" onchange="this.form.submit()">
            <option value="">Todos los programas</option>
            <?php foreach ($programas as $pr): ?>
            <option value="<?= $pr['id'] ?>" <?= $pr['id']==$filtroPrograma?'selected':''?>><?= sanitize($pr['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--gris-text);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;font-family:'Nunito',sans-serif">Buscar</label>
        <div style="display:flex;gap:6px">
            <input type="text" name="q" class="search-input" placeholder="Nombre o código..." value="<?= sanitize($search) ?>">
            <?php if ($filtroPrograma): ?><input type="hidden" name="programa_id" value="<?= $filtroPrograma ?>"><?php endif; ?>
            <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
            <?php if ($search || $filtroPrograma): ?><a href="competencias.php" class="btn btn-sm" style="background:#eee;color:#666">✕</a><?php endif; ?>
        </div>
    </div>
</form>

<!-- LAYOUT PRINCIPAL: tabla + panel lateral -->
<div style="display:grid;grid-template-columns:<?= $compActiva && $compInfo ? '1fr 400px' : '1fr' ?>;gap:20px;align-items:start">

<!-- ── TABLA COMPETENCIAS ───────────────────────── -->
<div class="table-card">
    <div class="table-card-header">
        <div class="table-card-title">Competencias (<?= count($comps) ?>)</div>
        <?php if (!$compActiva): ?>
        <span style="font-size:12px;color:var(--gris-text)">👆 Clic en una fila para ver sus resultados</span>
        <?php endif; ?>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Competencia</th>
                    <th>Programa</th>
                    <th style="text-align:center">Trim.</th>
                    <th style="text-align:center">Resultados</th>
                    <th>Opciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($comps)): ?>
                <tr><td colspan="5"><div class="empty-state"><div class="icon">📚</div><p>No hay competencias registradas.</p></div></td></tr>
            <?php else: foreach ($comps as $c):
                $esActiva = ($c['id'] == $compActiva);
            ?>
                <tr style="cursor:pointer;<?= $esActiva?'background:var(--verde-pale);':'' ?><?= !$c['activa']?'opacity:.5;':'' ?>"
                    onclick="window.location='<?= $urlBase ?><?= $urlBase?'&':'' ?>comp=<?= $c['id'] ?>'">
                    <td>
                        <strong style="font-family:'Nunito',sans-serif;font-size:13px"><?= sanitize($c['nombre']) ?></strong>
                        <?php if ($c['codigo']): ?><br><small style="color:var(--gris-text);font-family:monospace"><?= sanitize($c['codigo']) ?></small><?php endif; ?>
                        <?php if (!$c['activa']): ?><span class="badge badge-inactivo" style="margin-left:4px">Inactiva</span><?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--gris-text)"><?= sanitize($c['programa_nombre']) ?></td>
                    <td style="text-align:center"><span class="badge badge-proceso">T<?= $c['trimestre'] ?></span></td>
                    <td style="text-align:center">
                        <span style="font-weight:700;color:<?= $c['n_resultados']>0?'var(--verde-dark)':'#ccc' ?>"><?= $c['n_resultados'] ?></span>
                    </td>
                    <td onclick="event.stopPropagation()">
                        <div style="display:flex;gap:4px">
                            <button type="button" onclick="event.stopPropagation();editComp(<?= json_encode($c, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)" class="btn btn-sm btn-secondary">✎</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta competencia?')">
                                <input type="hidden" name="_accion" value="eliminar">
                                <input type="hidden" name="comp_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">✕</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── PANEL RESULTADOS (derecha) ──────────────── -->
<?php if ($compActiva && $compInfo): ?>
<div>
    <div style="background:var(--verde-pale);border:1.5px solid var(--verde-muted);border-radius:var(--radius);padding:14px 16px;margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
            <div>
                <div style="font-family:'Nunito',sans-serif;font-weight:800;font-size:13px;color:var(--negro);line-height:1.3"><?= sanitize($compInfo['nombre']) ?></div>
                <div style="font-size:11px;color:var(--gris-text);margin-top:4px">
                    <?= sanitize($compInfo['programa_nombre']) ?> · T<?= $compInfo['trimestre'] ?>
                    <?= $compInfo['horas'] ? ' · '.$compInfo['horas'].'h' : '' ?>
                </div>
            </div>
            <a href="<?= $urlBase ?>" class="btn btn-sm btn-secondary" style="flex-shrink:0">✕ Cerrar</a>
        </div>
    </div>

    <div class="table-card">
        <div class="table-card-header" style="padding:12px 16px">
            <div class="table-card-title" style="font-size:14px">📋 Resultados de Aprendizaje</div>
            <button class="btn btn-primary btn-sm"
                onclick="abrirNuevoResultado(<?= $compActiva ?>, '<?= addslashes(sanitize($compInfo['nombre'])) ?>')">
                + Agregar
            </button>
        </div>

        <?php if (empty($resultados)): ?>
        <div class="empty-state" style="padding:24px 12px">
            <div class="icon" style="font-size:26px">📋</div>
            <p style="font-size:12px">Sin resultados de aprendizaje aún.</p>
            <button class="btn btn-primary btn-sm" style="margin-top:10px"
                onclick="abrirNuevoResultado(<?= $compActiva ?>, '<?= addslashes(sanitize($compInfo['nombre'])) ?>')">
                + Agregar el primero
            </button>
        </div>
        <?php else: ?>
        <?php foreach ($resultados as $i => $ra): ?>
        <div style="display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-bottom:1px solid var(--gris-border)">
            <span style="min-width:20px;height:20px;background:var(--verde-pale);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--verde-dark);flex-shrink:0;margin-top:2px"><?= $i+1 ?></span>
            <div style="flex:1;min-width:0">
                <div style="font-size:12.5px;font-weight:600;line-height:1.4"><?= sanitize($ra['nombre']) ?></div>
                <?php if ($ra['codigo']): ?><div style="font-size:11px;color:var(--gris-text);font-family:monospace"><?= sanitize($ra['codigo']) ?></div><?php endif; ?>
            </div>
            <div style="display:flex;gap:3px;flex-shrink:0">
                <button onclick="editarResultado(<?= json_encode($ra, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>, '<?= addslashes(sanitize($compInfo['nombre'])) ?>')"
                        class="btn btn-icon btn-secondary" style="padding:4px 8px;font-size:13px">✎</button>
                <a href="<?= $urlBase ?><?= $urlBase?'&':'' ?>comp=<?= $compActiva ?>&del_res=<?= $ra['id'] ?>"
                   onclick="return confirm('¿Eliminar este resultado de aprendizaje?')"
                   class="btn btn-icon btn-danger" style="padding:4px 8px;font-size:13px">✕</a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div><!-- /grid -->

<!-- ═══════ MODAL Competencia ═══════ -->
<div class="modal-overlay" id="modalComp">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="titleComp">Nueva Competencia</div>
            <button class="modal-close" onclick="closeModal('modalComp')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="_accion" value="guardar">
            <input type="hidden" name="comp_id" id="cId" value="0">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" id="c_nombre" required placeholder="Ej: Analizar el funcionamiento de redes...">
                    </div>
                    <div class="form-group">
                        <label>Código <small style="font-weight:400">(opcional)</small></label>
                        <input type="text" name="codigo" id="c_codigo" placeholder="Ej: 220501001">
                    </div>
                    <div class="form-group">
                        <label>Programa *</label>
                        <select name="programa_id" id="c_programa" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($programas as $pr): ?>
                            <option value="<?= $pr['id'] ?>"><?= sanitize($pr['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Trimestre</label>
                        <select name="trimestre" id="c_trimestre">
                            <?php for ($t=1;$t<=12;$t++): ?><option value="<?=$t?>">Trimestre <?=$t?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Horas</label>
                        <input type="number" name="horas" id="c_horas" min="0" placeholder="0">
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:22px">
                        <input type="checkbox" name="activa" id="c_activa" value="1" checked style="width:auto">
                        <label for="c_activa" style="text-transform:none;font-size:13px">Competencia activa</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalComp')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Competencia</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════ MODAL Resultado ═══════ -->
<div class="modal-overlay" id="modalRes">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="titleRes">Nuevo Resultado de Aprendizaje</div>
            <button class="modal-close" onclick="closeModal('modalRes')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="_res"    value="1">
            <input type="hidden" name="rid"     id="rId"     value="0">
            <input type="hidden" name="comp_id" id="rCompId" value="0">
            <div class="modal-body">
                <div id="rChip" style="background:var(--verde-pale);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:16px;font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;color:var(--verde-dark)"></div>
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Descripción del Resultado *</label>
                        <textarea name="nombre_res" id="rNombre" required rows="3"
                            placeholder="Ej: Configurar dispositivos de red de acuerdo con el diseño establecido..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Código <small style="font-weight:400">(opcional)</small></label>
                        <input type="text" name="codigo_res" id="rCodigo" placeholder="Ej: 220501001-01">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalRes')">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnRes">Guardar Resultado</button>
            </div>
        </form>
    </div>
</div>

<script>
function resetComp() {
    document.getElementById('titleComp').textContent = 'Nueva Competencia';
    document.getElementById('cId').value         = 0;
    document.getElementById('c_nombre').value    = '';
    document.getElementById('c_codigo').value    = '';
    document.getElementById('c_programa').value  = '';
    document.getElementById('c_trimestre').value = 1;
    document.getElementById('c_horas').value     = 0;
    document.getElementById('c_activa').checked  = true;
}
function editComp(d) {
    document.getElementById('titleComp').textContent = 'Editar Competencia';
    document.getElementById('cId').value         = d.id;
    document.getElementById('c_nombre').value    = d.nombre;
    document.getElementById('c_codigo').value    = d.codigo || '';
    document.getElementById('c_programa').value  = d.programa_id;
    document.getElementById('c_trimestre').value = d.trimestre;
    document.getElementById('c_horas').value     = d.horas || 0;
    document.getElementById('c_activa').checked  = d.activa == 1;
    openModal('modalComp');
}
function abrirNuevoResultado(compId, compNombre) {
    document.getElementById('titleRes').textContent  = 'Nuevo Resultado de Aprendizaje';
    document.getElementById('rId').value             = 0;
    document.getElementById('rCompId').value         = compId;
    document.getElementById('rNombre').value         = '';
    document.getElementById('rCodigo').value         = '';
    document.getElementById('rChip').textContent     = '📚 ' + compNombre;
    document.getElementById('btnRes').textContent    = 'Guardar Resultado';
    openModal('modalRes');
}
function editarResultado(d, compNombre) {
    document.getElementById('titleRes').textContent  = 'Editar Resultado';
    document.getElementById('rId').value             = d.id;
    document.getElementById('rCompId').value         = d.competencia_id;
    document.getElementById('rNombre').value         = d.nombre;
    document.getElementById('rCodigo').value         = d.codigo || '';
    document.getElementById('rChip').textContent     = '📚 ' + (compNombre || 'Competencia');
    document.getElementById('btnRes').textContent    = 'Guardar Cambios';
    openModal('modalRes');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
