<?php
// pages/disciplinario.php
// MÃ³dulo de seguimiento disciplinario â€” gestionado por el Gestor del grupo.
// El Coordinador puede ver en modo lectura.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/expediente_schema.php';
require_once __DIR__ . '/../includes/disciplinario_schema.php';
requireLogin();
denyIfAprendiz(); // Aprendiz no tiene acceso a esta pÃ¡gina

$pageTitle = 'Seguimiento Disciplinario';
$db   = getDB();
ensureExpedienteSchema($db);
ensureDisciplinarioSchema($db);

$user   = getCurrentUser();
$rol    = $user['rol'] ?? '';
$userId = (int)($user['id'] ?? 0);

// Solo gestores, coordinadores y admins acceden
if (!hasRole(['Instructor','Gestor','Coordinador','Administrador'])) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$soloLectura = hasRole(['Coordinador']) && !hasRole(['Administrador']);
$puedeRemitirComite = hasRole(['Gestor','Administrador']);
$msg = $err = '';
$tab = $_GET['tab'] ?? 'hechos'; // hechos | atenciones | seguimiento

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// POST â€” Guardar hecho
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$soloLectura) {
    verifyCsrf();
    $form = $_POST['form'] ?? '';

    // â”€â”€ HECHO â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($form === 'hecho') {
        $editId = (int)($_POST['edit_id'] ?? 0);
        $data = [
            'aprendiz_id' => (int)($_POST['aprendiz_id'] ?? 0),
            'ficha_id'    => (int)($_POST['ficha_id']    ?? 0) ?: null,
            'gestor_id'   => $userId,
            'fecha_hecho' => $_POST['fecha_hecho'] ?? date('Y-m-d'),
            'fecha_limite_atencion' => $_POST['fecha_limite_atencion'] ?: null,
            'tipo_hecho'  => $_POST['tipo_hecho']  ?? '',
            'gravedad'    => $_POST['gravedad'] ?? 'Falta leve',
            'descripcion' => trim($_POST['descripcion'] ?? ''),
            'lugar'       => trim($_POST['lugar']       ?? ''),
            'testigos'    => trim($_POST['testigos']    ?? ''),
            'estado'      => $_POST['estado'] ?? 'Abierto',
        ];
        if (!$data['aprendiz_id'] || !$data['tipo_hecho'] || !$data['descripcion']) {
            $err = 'Complete aprendiz, tipo de hecho y descripciÃ³n.';
        } else {
            if ($editId) {
                $prev = $db->prepare("SELECT estado_flujo FROM disc_hechos WHERE id=?");
                $prev->execute([$editId]);
                $estadoAnterior = $prev->fetchColumn() ?: null;
                $stmt = $db->prepare("UPDATE disc_hechos SET aprendiz_id=?,ficha_id=?,fecha_hecho=?,fecha_limite_atencion=?,tipo_hecho=?,gravedad=?,descripcion=?,lugar=?,testigos=?,estado=?,estado_flujo=? WHERE id=?");
                $stmt->execute([$data['aprendiz_id'],$data['ficha_id'],$data['fecha_hecho'],$data['fecha_limite_atencion'],$data['tipo_hecho'],$data['gravedad'],$data['descripcion'],$data['lugar'],$data['testigos'],$data['estado'],$data['estado'],$editId]);
                registrarEventoDisciplinario($db, $editId, $data['aprendiz_id'], 'Hecho actualizado', $data['estado'], $data['descripcion'], $estadoAnterior, $data['fecha_limite_atencion']);
                $msg = 'Hecho actualizado.';
            } else {
                $stmt = $db->prepare("INSERT INTO disc_hechos (aprendiz_id,ficha_id,gestor_id,fecha_hecho,fecha_limite_atencion,tipo_hecho,gravedad,descripcion,lugar,testigos,estado,estado_flujo) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$data['aprendiz_id'],$data['ficha_id'],$data['gestor_id'],$data['fecha_hecho'],$data['fecha_limite_atencion'],$data['tipo_hecho'],$data['gravedad'],$data['descripcion'],$data['lugar'],$data['testigos'],$data['estado'],$data['estado']]);
                $hechoNuevoId = (int)$db->lastInsertId();
                registrarEventoDisciplinario($db, $hechoNuevoId, $data['aprendiz_id'], 'Hecho reportado', $data['estado'], $data['descripcion'], null, $data['fecha_limite_atencion']);
                notificarDisciplinario($db, $data['aprendiz_id'], $hechoNuevoId, 'Nuevo reporte disciplinario', "Se registro un hecho disciplinario.\nTipo: {$data['tipo_hecho']}\nGravedad: {$data['gravedad']}");
                evaluarEscalamientoDisciplinario($db, $data['aprendiz_id'], $hechoNuevoId, $data['gravedad'], $data['tipo_hecho']);
                $msg = 'Hecho disciplinario registrado.';
            }
            $tab = 'hechos';
        }
    }

    // â”€â”€ ATENCIÃ“N â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($form === 'atencion') {
        $editId      = (int)($_POST['edit_id'] ?? 0);
        $hechoId     = (int)($_POST['hecho_id'] ?? 0);
        $aprendizId  = (int)($_POST['aprendiz_id'] ?? 0);
        $firmaInst   = $_POST['firma_instructor'] ?? null;
        $firmaApr    = $_POST['firma_aprendiz']   ?? null;
        $firmaGest   = $_POST['firma_gestor']     ?? null;

        $data = [
            'hecho_id'          => $hechoId,
            'aprendiz_id'       => $aprendizId,
            'gestor_id'         => $userId,
            'instructor_id'     => (int)($_POST['instructor_id'] ?? 0) ?: null,
            'fecha_citacion'    => $_POST['fecha_citacion'] ?? date('Y-m-d'),
            'tipo_atencion'     => $_POST['tipo_atencion'] ?? 'Llamado de atenciÃ³n verbal',
            'descripcion'       => trim($_POST['descripcion_atencion'] ?? ''),
            'descargos_aprendiz'=> trim($_POST['descargos_aprendiz'] ?? ''),
            'compromisos'       => trim($_POST['compromisos'] ?? ''),
            'fecha_seguimiento' => $_POST['fecha_seguimiento'] ?: null,
            'resultado'         => $_POST['resultado_atencion'] ?? 'Pendiente',
        ];

        if (!$data['hecho_id'] || !$data['descripcion']) {
            $err = 'Seleccione el hecho y describa la atenciÃ³n.';
        } else {
            // Subida de archivo soporte
            $archivoNombre = null;
            $archivoRuta   = null;
            if (!empty($_FILES['soporte_atencion']['name'])) {
                $ext   = strtolower(pathinfo($_FILES['soporte_atencion']['name'], PATHINFO_EXTENSION));
                $allow = ['pdf','doc','docx','jpg','jpeg','png'];
                if (!in_array($ext, $allow)) {
                    $err = 'Formato no permitido. Use PDF, DOC, DOCX o imagen.';
                } else {
                    $archivoNombre = $_FILES['soporte_atencion']['name'];
                    $stored = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    move_uploaded_file($_FILES['soporte_atencion']['tmp_name'], disciplinarioUploadDir() . '/' . $stored);
                    $archivoRuta = 'uploads/disciplinario/' . $stored;
                }
            }

            if (!$err) {
                if ($editId) {
                    $sql = "UPDATE disc_atenciones SET instructor_id=?,fecha_citacion=?,tipo_atencion=?,descripcion=?,descargos_aprendiz=?,compromisos=?,fecha_seguimiento=?,resultado=?,firma_instructor=?,firma_aprendiz=?,firma_gestor=?" .
                           ($archivoRuta ? ",archivo_ruta=?,archivo_nombre=?" : "") . " WHERE id=?";
                    $params = [$data['instructor_id'],$data['fecha_citacion'],$data['tipo_atencion'],$data['descripcion'],$data['descargos_aprendiz'],$data['compromisos'],$data['fecha_seguimiento'],$data['resultado'],$firmaInst?:null,$firmaApr?:null,$firmaGest?:null];
                    if ($archivoRuta) { $params[] = $archivoRuta; $params[] = $archivoNombre; }
                    $params[] = $editId;
                    $db->prepare($sql)->execute($params);
                    registrarEventoDisciplinario($db, $hechoId, $aprendizId, 'Atencion actualizada', $data['resultado'], $data['descripcion'], null, $data['fecha_seguimiento'], $editId);
                    $msg = 'AtenciÃ³n actualizada.';
                } else {
                    $stmt = $db->prepare("INSERT INTO disc_atenciones (hecho_id,aprendiz_id,gestor_id,instructor_id,fecha_citacion,tipo_atencion,descripcion,descargos_aprendiz,compromisos,fecha_seguimiento,resultado,firma_instructor,firma_aprendiz,firma_gestor,archivo_ruta,archivo_nombre) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$data['hecho_id'],$data['aprendiz_id'],$data['gestor_id'],$data['instructor_id'],$data['fecha_citacion'],$data['tipo_atencion'],$data['descripcion'],$data['descargos_aprendiz'],$data['compromisos'],$data['fecha_seguimiento'],$data['resultado'],$firmaInst?:null,$firmaApr?:null,$firmaGest?:null,$archivoRuta,$archivoNombre]);
                    $atencionId = (int)$db->lastInsertId();
                    registrarEventoDisciplinario($db, $hechoId, $aprendizId, 'Atencion registrada', $data['compromisos'] ? 'Compromiso firmado' : 'Atencion registrada', $data['descripcion'], 'Abierto', $data['fecha_seguimiento'], $atencionId);
                    notificarDisciplinario($db, $aprendizId, $hechoId, 'Atencion disciplinaria registrada', "Se registro una atencion disciplinaria.\nTipo: {$data['tipo_atencion']}\nResultado: {$data['resultado']}");
                    // Actualizar estado del hecho
                    $db->prepare("UPDATE disc_hechos SET estado='En atenciÃ³n' WHERE id=? AND estado='Abierto'")->execute([$hechoId]);
                    // Si hay compromiso â†’ estado Comprometido
                    if ($data['compromisos']) {
                        $db->prepare("UPDATE disc_hechos SET estado='Comprometido' WHERE id=?")->execute([$hechoId]);
                    }
                    $msg = 'AtenciÃ³n registrada.';
                }
                $tab = 'atenciones';
            }
        }
    }

    // â”€â”€ SEGUIMIENTO â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($form === 'seguimiento') {
        $hechoId    = (int)($_POST['hecho_id'] ?? 0);
        $aprendizId = (int)($_POST['aprendiz_id'] ?? 0);
        $resultado  = $_POST['resultado_seg'] ?? 'Cumpliendo';
        $obs        = trim($_POST['observacion'] ?? '');
        if (!$hechoId || !$obs) {
            $err = 'Seleccione el hecho y escriba la observaciÃ³n.';
        } else {
            $stmt = $db->prepare("INSERT INTO disc_seguimiento (hecho_id,aprendiz_id,gestor_id,fecha,observacion,resultado) VALUES(?,?,?,?,?,?)");
            $stmt->execute([$hechoId,$aprendizId,$userId,date('Y-m-d'),$obs,$resultado]);
            $seguimientoId = (int)$db->lastInsertId();
            // Actualizar estado del hecho segÃºn resultado
            $estadoMap = ['Cumpliendo'=>'Comprometido','Reincidencia'=>'Abierto','Cerrado'=>'Cerrado'];
            $db->prepare("UPDATE disc_hechos SET estado=? WHERE id=?")->execute([$estadoMap[$resultado] ?? 'En atenciÃ³n', $hechoId]);
            if ($resultado === 'Reincidencia') {
                $db->prepare("UPDATE disc_hechos SET remitir_comite=1 WHERE id=?")->execute([$hechoId]);
            }
            $flujoNuevo = $resultado === 'Reincidencia' ? 'Reincidencia' : ($resultado === 'Cerrado' ? 'Cerrado' : 'Seguimiento registrado');
            $db->prepare("UPDATE disc_hechos SET estado_flujo=?, fecha_cierre=IF(?='Cerrado', NOW(), fecha_cierre), motivo_cierre=IF(?='Cerrado', ?, motivo_cierre) WHERE id=?")->execute([$flujoNuevo, $resultado, $resultado, $obs, $hechoId]);
            registrarEventoDisciplinario($db, $hechoId, $aprendizId, 'Seguimiento registrado', $flujoNuevo, $obs, null, null, 0, $seguimientoId);
            if ($resultado === 'Reincidencia') {
                $infoHecho = $db->prepare("SELECT gravedad, tipo_hecho FROM disc_hechos WHERE id=?");
                $infoHecho->execute([$hechoId]);
                $hechoEscalar = $infoHecho->fetch();
                if ($hechoEscalar) {
                    evaluarEscalamientoDisciplinario($db, $aprendizId, $hechoId, $hechoEscalar['gravedad'] ?? 'Falta leve', $hechoEscalar['tipo_hecho'] ?? '');
                }
            }
            $msg = 'Seguimiento registrado.';
            $tab = 'seguimiento';
        }
    }

    // â”€â”€ REMITIR A COMITÃ‰ â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($form === 'comite' && $puedeRemitirComite) {
        $hechoId    = (int)($_POST['hecho_id'] ?? 0);
        $aprendizId = (int)($_POST['aprendiz_id'] ?? 0);
        $motivo     = trim($_POST['motivo_comite'] ?? '');
        if (!$hechoId || !$motivo) {
            $err = 'Indique el motivo de remisiÃ³n a comitÃ©.';
        } else {
            $db->prepare("UPDATE disc_hechos SET estado='Remitido a comitÃ©',remitir_comite=1 WHERE id=?")->execute([$hechoId]);
            $stmt = $db->prepare("INSERT INTO comite_aprendices (aprendiz_id,fecha_remision,motivo_remision,decision,caso_excepcional,observaciones_comite,validacion_expediente) VALUES(?,?,?,?,?,?,?)");
            $stmt->execute([$aprendizId,date('Y-m-d'),'[DISCIPLINARIO] '.$motivo,'Pendiente',0,'','Caso disciplinario']);
            $db->prepare("UPDATE disc_hechos SET estado_flujo='Remitido a comite' WHERE id=?")->execute([$hechoId]);
            registrarEventoDisciplinario($db, $hechoId, $aprendizId, 'Remitido a comite', 'Remitido a comite', $motivo, null);
            notificarDisciplinario($db, $aprendizId, $hechoId, 'Caso disciplinario remitido a comite', $motivo);
            $msg = 'Aprendiz remitido a comitÃ© disciplinario.';
            $tab = 'hechos';
        }
    }
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// Datos para listados
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
$ff = filtroFichas($db, 'a');

// Aprendices disponibles para el gestor
$aprendices = $db->query("
    SELECT a.id, CONCAT(a.apellidos,', ',a.nombres) AS nombre,
           a.documento, f.id AS ficha_id, f.numero_ficha
    FROM aprendices a
    JOIN fichas f ON f.id=a.ficha_id
    WHERE a.estado='Activo'
    ORDER BY a.apellidos, a.nombres
")->fetchAll();

$instructores = $db->query("SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM instructores WHERE activo=1 ORDER BY nombres")->fetchAll();
$fichasFiltro = $db->query("SELECT id, numero_ficha FROM fichas ORDER BY numero_ficha")->fetchAll();

$filtrosDisc = [
    'q' => trim($_GET['q'] ?? ''),
    'ficha_id' => (int)($_GET['ficha_id'] ?? 0),
    'estado' => $_GET['estado'] ?? '',
    'flujo' => $_GET['flujo'] ?? '',
    'gravedad' => $_GET['gravedad'] ?? '',
    'desde' => $_GET['desde'] ?? '',
    'hasta' => $_GET['hasta'] ?? '',
];
$whereDisc = [];
$paramsDisc = [];
if ($filtrosDisc['q'] !== '') {
    $whereDisc[] = "(a.nombres LIKE ? OR a.apellidos LIKE ? OR a.documento LIKE ? OR dh.tipo_hecho LIKE ? OR dh.descripcion LIKE ?)";
    $paramsDisc = array_merge($paramsDisc, array_fill(0, 5, '%' . $filtrosDisc['q'] . '%'));
}
if ($filtrosDisc['ficha_id']) {
    $whereDisc[] = "f.id=?";
    $paramsDisc[] = $filtrosDisc['ficha_id'];
}
if ($filtrosDisc['estado'] !== '') {
    $whereDisc[] = "dh.estado=?";
    $paramsDisc[] = $filtrosDisc['estado'];
}
if ($filtrosDisc['flujo'] !== '') {
    $whereDisc[] = "dh.estado_flujo=?";
    $paramsDisc[] = $filtrosDisc['flujo'];
}
if ($filtrosDisc['gravedad'] !== '') {
    $whereDisc[] = "dh.gravedad=?";
    $paramsDisc[] = $filtrosDisc['gravedad'];
}
if ($filtrosDisc['desde'] !== '') {
    $whereDisc[] = "dh.fecha_hecho>=?";
    $paramsDisc[] = $filtrosDisc['desde'];
}
if ($filtrosDisc['hasta'] !== '') {
    $whereDisc[] = "dh.fecha_hecho<=?";
    $paramsDisc[] = $filtrosDisc['hasta'];
}
$whereSqlDisc = $whereDisc ? ('WHERE ' . implode(' AND ', $whereDisc)) : '';

// Hechos
$stmtHechos = $db->prepare("
    SELECT dh.*,
           CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre, a.documento,
           f.numero_ficha,
           CONCAT(u.nombres,' ',u.apellidos) AS gestor_nombre,
           (SELECT COUNT(*) FROM disc_atenciones da WHERE da.hecho_id=dh.id) AS total_atenciones,
           (SELECT COUNT(*) FROM disc_seguimiento ds WHERE ds.hecho_id=dh.id) AS total_seguimientos,
           (SELECT COUNT(*) FROM disc_atenciones da WHERE da.hecho_id=dh.id AND da.resultado='Reincidencia') AS reincidencias,
           (SELECT COUNT(*) FROM disc_evidencias de WHERE de.hecho_id=dh.id) AS total_evidencias
    FROM disc_hechos dh
    JOIN aprendices a ON a.id=dh.aprendiz_id
    JOIN fichas f ON f.id=a.ficha_id
    JOIN usuarios u ON u.id=dh.gestor_id
    $whereSqlDisc
    ORDER BY dh.created_at DESC
");
$stmtHechos->execute($paramsDisc);
$hechos = $stmtHechos->fetchAll();

// Atenciones
$atenciones = $db->query("
    SELECT da.*,
           CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre,
           CONCAT(i.nombres,' ',i.apellidos) AS instructor_nombre,
           dh.tipo_hecho, dh.fecha_hecho,
           (SELECT COUNT(*) FROM disc_evidencias de WHERE de.atencion_id=da.id OR de.hecho_id=da.hecho_id) AS total_evidencias,
           (SELECT de.archivo_ruta FROM disc_evidencias de WHERE de.atencion_id=da.id OR de.hecho_id=da.hecho_id ORDER BY de.created_at DESC LIMIT 1) AS evidencia_ruta,
           (SELECT de.archivo_nombre FROM disc_evidencias de WHERE de.atencion_id=da.id OR de.hecho_id=da.hecho_id ORDER BY de.created_at DESC LIMIT 1) AS evidencia_nombre
    FROM disc_atenciones da
    JOIN aprendices a ON a.id=da.aprendiz_id
    JOIN disc_hechos dh ON dh.id=da.hecho_id
    LEFT JOIN instructores i ON i.id=da.instructor_id
    ORDER BY da.created_at DESC
")->fetchAll();

// Seguimientos
$seguimientos = $db->query("
    SELECT ds.*,
           CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre,
           dh.tipo_hecho
    FROM disc_seguimiento ds
    JOIN aprendices a ON a.id=ds.aprendiz_id
    JOIN disc_hechos dh ON dh.id=ds.hecho_id
    ORDER BY ds.created_at DESC
")->fetchAll();

$eventosDisc = $db->query("
    SELECT dfe.*,
           CONCAT(a.nombres,' ',a.apellidos) AS aprendiz_nombre,
           a.documento,
           dh.tipo_hecho,
           dh.gravedad,
           CONCAT(u.nombres,' ',u.apellidos) AS creado_por_nombre
    FROM disc_flujo_eventos dfe
    JOIN aprendices a ON a.id=dfe.aprendiz_id
    JOIN disc_hechos dh ON dh.id=dfe.hecho_id
    LEFT JOIN usuarios u ON u.id=dfe.creado_por
    ORDER BY dfe.created_at DESC, dfe.id DESC
    LIMIT 200
")->fetchAll();

// Stats resumen
$statsDisc = [
    'abiertos'    => count(array_filter($hechos, fn($h) => $h['estado'] === 'Abierto')),
    'en_atencion' => count(array_filter($hechos, fn($h) => in_array($h['estado'], ['En atenciÃ³n','Comprometido']))),
    'reincidentes'=> count(array_filter($hechos, fn($h) => $h['reincidencias'] > 0)),
    'comite'      => count(array_filter($hechos, fn($h) => $h['estado'] === 'Remitido a comitÃ©')),
    'cerrados'    => count(array_filter($hechos, fn($h) => $h['estado'] === 'Cerrado')),
];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.disc-tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:2px solid var(--gris-border); padding-bottom:0; }
.disc-tab  { padding:10px 20px; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; border:none; background:none; color:var(--gris-text); border-bottom:3px solid transparent; margin-bottom:-2px; transition:.2s; border-radius:6px 6px 0 0; }
.disc-tab:hover  { color:var(--verde-dark); background:var(--verde-pale); }
.disc-tab.active { color:var(--verde-dark); border-bottom-color:var(--verde); background:var(--verde-pale); }
.disc-panel { display:none; } .disc-panel.active { display:block; }
.estado-abierto     { background:#fef3cd; color:#856404; }
.estado-atencion    { background:#d1ecf1; color:#0c5460; }
.estado-comprometido{ background:#e8daff; color:#5b21b6; }
.estado-cerrado     { background:var(--verde-pale); color:var(--verde-dark); }
.estado-comite      { background:#f8d7da; color:#721c24; }
.firma-canvas { border:1.5px solid var(--gris-border); border-radius:8px; cursor:crosshair; background:#fff; touch-action:none; display:block; width:100%; height:90px; }
.firma-wrap   { border:1px solid var(--gris-border); border-radius:10px; overflow:hidden; background:#fafafa; }
.firma-label  { padding:8px 12px; font-size:10px; font-weight:700; color:var(--gris-text); text-transform:uppercase; letter-spacing:.8px; background:var(--gris-bg); border-bottom:1px solid var(--gris-border); display:flex; justify-content:space-between; align-items:center; }
</style>

<div class="page-header">
    <div>
        <div class="page-title">Seguimiento Disciplinario</div>
        <div class="page-subtitle">
            Registro de hechos, atenciones, descargos, compromisos y seguimiento por aprendiz
            <?= $soloLectura ? ' â€” <span style="color:var(--naranja)">Vista de solo lectura</span>' : '' ?>
        </div>
    </div>
    <?php if (!$soloLectura): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary" onclick="openModal('modalHecho')">+ Registrar hecho</button>
        <button class="btn btn-secondary" onclick="openModal('modalAtencion')">+ Registrar atenciÃ³n</button>
    </div>
    <?php endif; ?>

</div>

<?php if ($msg): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div class="table-card" style="padding:16px;margin-bottom:18px">
    <form method="GET" class="form-grid" style="align-items:end">
        <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
        <div class="form-group">
            <label>Buscar</label>
            <input type="text" name="q" value="<?= sanitize($filtrosDisc['q']) ?>" placeholder="Aprendiz, documento, tipo o descripcion">
        </div>
        <div class="form-group">
            <label>Ficha</label>
            <select name="ficha_id">
                <option value="">Todas</option>
                <?php foreach ($fichasFiltro as $ffItem): ?>
                <option value="<?= (int)$ffItem['id'] ?>" <?= $filtrosDisc['ficha_id']===(int)$ffItem['id'] ? 'selected' : '' ?>><?= sanitize($ffItem['numero_ficha']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Gravedad</label>
            <select name="gravedad">
                <option value="">Todas</option>
                <?php foreach (['Falta leve','Falta grave','Falta gravisima'] as $g): ?>
                <option value="<?= $g ?>" <?= $filtrosDisc['gravedad']===$g ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Estado</label>
            <select name="estado">
                <option value="">Todos</option>
                <?php foreach (['Abierto','En atenciÃ³n','Comprometido','Cerrado','Remitido a comitÃ©'] as $e): ?>
                <option value="<?= $e ?>" <?= $filtrosDisc['estado']===$e ? 'selected' : '' ?>><?= $e ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Instancia</label>
            <select name="flujo">
                <option value="">Todas</option>
                <?php foreach (['Reportado','Primera instancia','Segunda instancia','Listo para comite','Remitido a comite','Cerrado'] as $fl): ?>
                <option value="<?= $fl ?>" <?= $filtrosDisc['flujo']===$fl ? 'selected' : '' ?>><?= $fl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>Desde</label><input type="date" name="desde" value="<?= sanitize($filtrosDisc['desde']) ?>"></div>
        <div class="form-group"><label>Hasta</label><input type="date" name="hasta" value="<?= sanitize($filtrosDisc['hasta']) ?>"></div>
        <div class="form-group" style="display:flex;gap:8px;flex-direction:row">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a href="disciplinario.php?tab=<?= urlencode($tab) ?>" class="btn btn-secondary">Limpiar</a>
        </div>
    </form>
</div>

<!-- STATS -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card warning"><span class="stat-icon">âš </span><div class="stat-value"><?= $statsDisc['abiertos'] ?></div><div class="stat-label">Abiertos</div></div>
    <div class="stat-card info">   <span class="stat-icon">â—</span><div class="stat-value"><?= $statsDisc['en_atencion'] ?></div><div class="stat-label">En atenciÃ³n</div></div>
    <div class="stat-card danger"> <span class="stat-icon">â†º</span><div class="stat-value"><?= $statsDisc['reincidentes'] ?></div><div class="stat-label">Reincidentes</div></div>
    <div class="stat-card danger"> <span class="stat-icon">â—‘</span><div class="stat-value"><?= $statsDisc['comite'] ?></div><div class="stat-label">En comitÃ©</div></div>
    <div class="stat-card">        <span class="stat-icon">âœ“</span><div class="stat-value"><?= $statsDisc['cerrados'] ?></div><div class="stat-label">Cerrados</div></div>
</div>

<!-- TABS -->
<div class="disc-tabs">
    <button class="disc-tab <?= $tab==='hechos'      ? 'active':'' ?>" onclick="switchTab('hechos')">âš  Hechos (<?= count($hechos) ?>)</button>
    <button class="disc-tab <?= $tab==='atenciones'  ? 'active':'' ?>" onclick="switchTab('atenciones')">ðŸ“‹ Atenciones (<?= count($atenciones) ?>)</button>
    <button class="disc-tab <?= $tab==='seguimiento' ? 'active':'' ?>" onclick="switchTab('seguimiento')">â—Ž Seguimiento (<?= count($seguimientos) ?>)</button>
    <button class="disc-tab <?= $tab==='trazabilidad' ? 'active':'' ?>" onclick="switchTab('trazabilidad')">H Trazabilidad (<?= count($eventosDisc) ?>)</button>
</div>

<!-- â•â•â•â• TAB HECHOS â•â•â•â• -->
<div class="disc-panel <?= $tab==='hechos' ? 'active':'' ?>" id="tab-hechos">
<?php if (empty($hechos)): ?>
    <div class="table-card"><div class="empty-state" style="padding:60px 20px"><div class="icon">ðŸ“‹</div><p>No hay hechos disciplinarios registrados.</p></div></div>
<?php else: ?>
    <div class="table-card">
        <div class="table-card-header"><div class="table-card-title">Hechos disciplinarios</div></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Aprendiz</th><th>Ficha</th><th>Fecha</th><th>Tipo</th><th>Estado</th><th>Atenciones</th><th>Reinc.</th><th>Opciones</th></tr></thead>
                <tbody>
                <?php foreach ($hechos as $h):
                    $estCls = match($h['estado']) {
                        'Abierto'           => 'estado-abierto',
                        'En atenciÃ³n'       => 'estado-atencion',
                        'Comprometido'      => 'estado-comprometido',
                        'Cerrado'           => 'estado-cerrado',
                        'Remitido a comitÃ©' => 'estado-comite',
                        default             => ''
                    };
                ?>
                <tr>
                    <td><strong><?= sanitize($h['aprendiz_nombre']) ?></strong><br><small style="color:#888"><?= sanitize($h['documento']) ?></small></td>
                    <td><?= sanitize($h['numero_ficha']) ?></td>
                    <td><?= date('d/m/Y', strtotime($h['fecha_hecho'])) ?></td>
                    <td style="font-size:11px;max-width:160px">
                        <?= sanitize($h['tipo_hecho']) ?>
                        <br><small><?= sanitize($h['gravedad'] ?? 'Falta leve') ?></small>
                    </td>
                    <td>
                        <span class="badge <?= $estCls ?>"><?= sanitize($h['estado']) ?></span>
                        <br><small><?= sanitize($h['estado_flujo'] ?? '') ?></small>
                        <?php if (!empty($h['fecha_limite_atencion'])): ?>
                            <br><small>Limite: <?= date('d/m/Y', strtotime($h['fecha_limite_atencion'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center"><strong><?= $h['total_atenciones'] ?></strong></td>
                    <td style="text-align:center;color:<?= $h['reincidencias']>0?'var(--rojo)':'inherit' ?>;font-weight:<?= $h['reincidencias']>0?'700':'400' ?>">
                        <?= $h['reincidencias'] > 0 ? 'âš  '.$h['reincidencias'] : 'â€”' ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if (!$soloLectura): ?>
                        <button onclick='editarHecho(<?= json_encode($h, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn btn-sm btn-secondary" title="Editar">âœŽ</button>
                        <button onclick='prepararAtencion(<?= $h['id'] ?>,<?= $h['aprendiz_id'] ?>,"<?= sanitize($h['aprendiz_nombre']) ?>")' class="btn btn-sm btn-primary" title="Registrar atenciÃ³n">+ AtenciÃ³n</button>
                        <?php if ($puedeRemitirComite && ($h['remitir_comite'] || $h['reincidencias'] > 0)): ?>
                        <button onclick='prepararComite(<?= $h['id'] ?>,<?= $h['aprendiz_id'] ?>,"<?= sanitize($h['aprendiz_nombre']) ?>")' class="btn btn-sm btn-danger" title="Remitir a comitÃ©">â†’ ComitÃ©</button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($h['descripcion']): ?>
                <tr style="background:var(--gris-bg)">
                    <td colspan="8" style="padding:4px 14px 10px 28px;font-size:11px;color:var(--gris-text)">
                        <strong>ðŸ“ DescripciÃ³n:</strong> <?= sanitize(substr($h['descripcion'],0,200)) ?>
                        <?= $h['lugar'] ? ' | <strong>Lugar:</strong> '.sanitize($h['lugar']) : '' ?>
                        <?= $h['testigos'] ? ' | <strong>Testigos:</strong> '.sanitize($h['testigos']) : '' ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>

<!-- â•â•â•â• TAB ATENCIONES â•â•â•â• -->
<div class="disc-panel <?= $tab==='atenciones' ? 'active':'' ?>" id="tab-atenciones">
<?php if (empty($atenciones)): ?>
    <div class="table-card"><div class="empty-state" style="padding:60px 20px"><div class="icon">ðŸ“‹</div><p>No hay atenciones registradas.</p></div></div>
<?php else: ?>
    <div class="table-card">
        <div class="table-card-header"><div class="table-card-title">Atenciones y descargos</div></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Aprendiz</th><th>Tipo hecho</th><th>Tipo atencion</th><th>Fecha</th><th>Resultado</th><th>Firma Inst.</th><th>Firma Apr.</th><th>Soporte</th><th>Evidencia</th><th>Acta</th></tr></thead>
                <tbody>
                <?php foreach ($atenciones as $at):
                    $resCls = match($at['resultado']) {
                        'Cumplido'    => 'badge-superado',
                        'Incumplido','Reincidencia' => 'badge-comite',
                        default => 'badge-proceso'
                    };
                ?>
                <tr>
                    <td><strong><?= sanitize($at['aprendiz_nombre']) ?></strong><br><small style="color:#888"><?= sanitize($at['instructor_nombre'] ?? 'â€”') ?></small></td>
                    <td style="font-size:11px"><?= sanitize($at['tipo_hecho']) ?></td>
                    <td style="font-size:11px"><?= sanitize($at['tipo_atencion']) ?></td>
                    <td><?= date('d/m/Y', strtotime($at['fecha_citacion'])) ?></td>
                    <td><span class="badge <?= $resCls ?>"><?= sanitize($at['resultado']) ?></span></td>
                    <td style="text-align:center">
                        <?php if ($at['firma_instructor']): ?>
                            <button onclick="verFirma('<?= htmlspecialchars($at['firma_instructor']) ?>','Instructor')" class="btn btn-sm btn-secondary">âœ Ver</button>
                        <?php else: ?><span style="color:#ccc">â€”</span><?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($at['firma_aprendiz']): ?>
                            <button onclick="verFirma('<?= htmlspecialchars($at['firma_aprendiz']) ?>','Aprendiz')" class="btn btn-sm btn-secondary">âœ Ver</button>
                        <?php else: ?><span style="color:#ccc;font-weight:700">âš  Sin firma</span><?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($at['archivo_ruta']): ?>
                            <a href="<?= BASE_URL.'/'.sanitize($at['archivo_ruta']) ?>" target="_blank" class="btn btn-sm btn-secondary">ðŸ“„</a>
                        <?php else: ?>â€”<?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if (!empty($at['evidencia_ruta'])): ?>
                            <a href="<?= BASE_URL.'/'.sanitize($at['evidencia_ruta']) ?>" target="_blank" class="btn btn-sm btn-secondary">Ver</a>
                            <br><small style="display:block;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= sanitize($at['evidencia_nombre'] ?? '') ?>"><?= sanitize($at['evidencia_nombre'] ?? '') ?></small>
                        <?php else: ?>
                            <span style="color:#aaa">Sin evidencia</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <a href="acta_disciplinaria.php?id=<?= (int)$at['id'] ?>" target="_blank" class="btn btn-sm btn-primary">Acta</a>
                    </td>
                </tr>
                <?php if ($at['descripcion'] || $at['compromisos']): ?>
                <tr style="background:var(--gris-bg)">
                    <td colspan="10" style="padding:4px 14px 10px 28px;font-size:11px;color:var(--gris-text)">
                        <?= $at['descripcion'] ? '<strong>AtenciÃ³n:</strong> '.sanitize(substr($at['descripcion'],0,160)) : '' ?>
                        <?= $at['compromisos'] ? ' | <strong>Compromiso:</strong> '.sanitize(substr($at['compromisos'],0,160)) : '' ?>
                        <?= $at['descargos_aprendiz'] ? ' | <strong>Descargos:</strong> '.sanitize(substr($at['descargos_aprendiz'],0,100)) : '' ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>

<!-- â•â•â•â• TAB SEGUIMIENTO â•â•â•â• -->
<div class="disc-panel <?= $tab==='seguimiento' ? 'active':'' ?>" id="tab-seguimiento">
    <?php if (!$soloLectura): ?>
    <div class="table-card" style="padding:20px;margin-bottom:16px">
        <div style="font-family:'Nunito',sans-serif;font-size:15px;font-weight:800;margin-bottom:14px">Registrar seguimiento</div>
        <form method="POST" class="form-grid">
            <input type="hidden" name="form" value="seguimiento">
            <div class="form-group">
                <label>Hecho disciplinario *</label>
                <select name="hecho_id" id="seg_hecho" required onchange="cargarAprendizSeg(this)">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($hechos as $h): ?>
                    <?php if (!in_array($h['estado'], ['Cerrado','Remitido a comitÃ©'])): ?>
                    <option value="<?= $h['id'] ?>" data-aprendiz="<?= $h['aprendiz_id'] ?>">
                        <?= sanitize($h['aprendiz_nombre']) ?> â€” <?= sanitize($h['tipo_hecho']) ?> (<?= date('d/m/Y',strtotime($h['fecha_hecho'])) ?>)
                    </option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="aprendiz_id" id="seg_aprendiz_id">
            <div class="form-group">
                <label>Resultado del seguimiento *</label>
                <select name="resultado_seg">
                    <option value="Cumpliendo">Cumpliendo el compromiso</option>
                    <option value="Reincidencia">Reincidencia âš ï¸</option>
                    <option value="Cerrado">Cerrado â€” resuelto</option>
                </select>
            </div>
            <div class="form-group full">
                <label>ObservaciÃ³n *</label>
                <textarea name="observacion" required placeholder="Describa el estado actual del compromiso o la reincidencia observada..."></textarea>
            </div>
            <div class="form-group full" style="display:flex;justify-content:flex-end">
                <button class="btn btn-primary" type="submit">Guardar seguimiento</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (empty($seguimientos)): ?>
        <div class="table-card"><div class="empty-state" style="padding:40px 20px"><p>No hay seguimientos registrados.</p></div></div>
    <?php else: ?>
    <div class="table-card">
        <div class="table-card-header"><div class="table-card-title">Historial de seguimiento</div></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Fecha</th><th>Aprendiz</th><th>Hecho</th><th>Resultado</th><th>ObservaciÃ³n</th></tr></thead>
                <tbody>
                <?php foreach ($seguimientos as $s):
                    $resCls = match($s['resultado']) {
                        'Cumpliendo' => 'badge-superado',
                        'Reincidencia' => 'badge-comite',
                        'Cerrado' => 'badge-proceso',
                        default => ''
                    };
                ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($s['fecha'])) ?></td>
                    <td><?= sanitize($s['aprendiz_nombre']) ?></td>
                    <td style="font-size:11px"><?= sanitize($s['tipo_hecho']) ?></td>
                    <td><span class="badge <?= $resCls ?>"><?= sanitize($s['resultado']) ?></span></td>
                    <td style="font-size:12px"><?= sanitize(substr($s['observacion'],0,160)) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- TAB TRAZABILIDAD -->
<div class="disc-panel <?= $tab==='trazabilidad' ? 'active':'' ?>" id="tab-trazabilidad">
    <div class="table-card">
        <div class="table-card-header"><div class="table-card-title">Trazabilidad disciplinaria</div></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Fecha</th><th>Aprendiz</th><th>Hecho</th><th>Evento</th><th>Estado</th><th>Detalle</th><th>Usuario</th></tr></thead>
                <tbody>
                <?php if (empty($eventosDisc)): ?>
                    <tr><td colspan="7"><div class="empty-state"><p>No hay trazabilidad disciplinaria registrada.</p></div></td></tr>
                <?php endif; ?>
                <?php foreach ($eventosDisc as $ev): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($ev['created_at'])) ?></td>
                        <td><strong><?= sanitize($ev['aprendiz_nombre']) ?></strong><br><small><?= sanitize($ev['documento']) ?></small></td>
                        <td style="font-size:12px"><?= sanitize($ev['tipo_hecho']) ?><br><small><?= sanitize($ev['gravedad'] ?? '') ?></small></td>
                        <td><strong><?= sanitize($ev['tipo_evento']) ?></strong></td>
                        <td><span class="badge badge-proceso"><?= sanitize($ev['estado_nuevo']) ?></span></td>
                        <td style="font-size:12px;max-width:360px">
                            <?= nl2br(sanitize($ev['descripcion'] ?? '')) ?>
                            <?php if (!empty($ev['fecha_limite'])): ?><br><strong>Fecha limite:</strong> <?= date('d/m/Y', strtotime($ev['fecha_limite'])) ?><?php endif; ?>
                        </td>
                        <td><?= sanitize($ev['creado_por_nombre'] ?? 'Sistema') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- â•â•â•â• MODAL HECHO â•â•â•â• -->
<div class="modal-overlay" id="modalHecho">
    <div class="modal" style="max-width:620px">
        <div class="modal-header">
            <div class="modal-title" id="titleHecho">Registrar hecho disciplinario</div>
            <button class="modal-close" onclick="closeModal('modalHecho')">âœ•</button>
        </div>
        <form method="POST" id="formHecho">
            <input type="hidden" name="form" value="hecho">
            <input type="hidden" name="edit_id" id="hecho_edit_id" value="0">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Aprendiz *</label>
                        <select name="aprendiz_id" id="hecho_aprendiz" required>
                            <option value="">-- Seleccionar aprendiz --</option>
                            <?php foreach ($aprendices as $ap): ?>
                            <option value="<?= $ap['id'] ?>" data-ficha="<?= $ap['ficha_id'] ?>">
                                <?= sanitize($ap['nombre']) ?> â€” Doc: <?= sanitize($ap['documento']) ?> / Ficha <?= sanitize($ap['numero_ficha']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="ficha_id" id="hecho_ficha_id">
                    </div>
                    <div class="form-group">
                        <label>Fecha del hecho *</label>
                        <input type="date" name="fecha_hecho" id="hecho_fecha" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha limite de atencion</label>
                        <input type="date" name="fecha_limite_atencion" id="hecho_limite">
                    </div>
                    <div class="form-group">
                        <label>Tipo de hecho *</label>
                        <select name="tipo_hecho" id="hecho_tipo" required>
                            <option value="">-- Seleccionar --</option>
                            <option>Inasistencia injustificada</option>
                            <option>AgresiÃ³n verbal</option>
                            <option>AgresiÃ³n fÃ­sica</option>
                            <option>Incumplimiento de normas</option>
                            <option>Comportamiento inadecuado</option>
                            <option>Uso indebido de dispositivos</option>
                            <option>Fraude acadÃ©mico</option>
                            <option>Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gravedad</label>
                        <select name="gravedad" id="hecho_gravedad">
                            <option>Falta leve</option>
                            <option>Falta grave</option>
                            <option>Falta gravisima</option>
                            <option>Caso excepcional</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Lugar</label>
                        <input type="text" name="lugar" id="hecho_lugar" placeholder="SalÃ³n, taller, etc.">
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado" id="hecho_estado">
                            <option>Abierto</option>
                            <option>En atenciÃ³n</option>
                            <option>Comprometido</option>
                            <option>Cerrado</option>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label>DescripciÃ³n del hecho *</label>
                        <textarea name="descripcion" id="hecho_desc" required placeholder="Describa detalladamente lo ocurrido, contexto y personas involucradas..."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Testigos</label>
                        <input type="text" name="testigos" id="hecho_testigos" placeholder="Nombres de testigos si aplica">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalHecho')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar hecho</button>
            </div>
        </form>
    </div>
</div>

<!-- â•â•â•â• MODAL ATENCIÃ“N â•â•â•â• -->
<div class="modal-overlay" id="modalAtencion">
    <div class="modal" style="max-width:700px">
        <div class="modal-header">
            <div class="modal-title">Registrar atenciÃ³n / descargos / compromiso</div>
            <button class="modal-close" onclick="closeModal('modalAtencion')">âœ•</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="formAtencion">
            <input type="hidden" name="form" value="atencion">
            <input type="hidden" name="edit_id" value="0">
            <input type="hidden" name="aprendiz_id" id="at_aprendiz_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Hecho disciplinario *</label>
                        <select name="hecho_id" id="at_hecho" required onchange="cargarAprendizAt(this)">
                            <option value="">-- Seleccionar hecho --</option>
                            <?php foreach ($hechos as $h): ?>
                            <?php if (!in_array($h['estado'],['Cerrado','Remitido a comitÃ©'])): ?>
                            <option value="<?= $h['id'] ?>" data-aprendiz="<?= $h['aprendiz_id'] ?>">
                                <?= sanitize($h['aprendiz_nombre']) ?> â€” <?= sanitize($h['tipo_hecho']) ?> (<?= date('d/m/Y',strtotime($h['fecha_hecho'])) ?>)
                            </option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Instructor relacionado</label>
                        <select name="instructor_id" id="at_instructor">
                            <option value="">-- Ninguno / No aplica --</option>
                            <?php foreach ($instructores as $i): ?>
                            <option value="<?= $i['id'] ?>"><?= sanitize($i['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha de citaciÃ³n *</label>
                        <input type="date" name="fecha_citacion" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tipo de atenciÃ³n *</label>
                        <select name="tipo_atencion">
                            <option>Llamado de atenciÃ³n verbal</option>
                            <option>Llamado de atenciÃ³n escrito</option>
                            <option>Descargos del aprendiz</option>
                            <option>Compromiso de mejora</option>
                            <option>RemisiÃ³n a bienestar</option>
                            <option>Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Resultado</label>
                        <select name="resultado_atencion">
                            <option>Pendiente</option>
                            <option>Cumplido</option>
                            <option>Incumplido</option>
                            <option>Reincidencia</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha de seguimiento</label>
                        <input type="date" name="fecha_seguimiento">
                    </div>
                    <div class="form-group full">
                        <label>DescripciÃ³n de la atenciÃ³n *</label>
                        <textarea name="descripcion_atencion" required placeholder="Â¿QuÃ© se hizo? Llamado de atenciÃ³n, citaciÃ³n, etc."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Descargos del aprendiz</label>
                        <textarea name="descargos_aprendiz" placeholder="VersiÃ³n del aprendiz, justificaciones dadas..."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Compromisos acordados</label>
                        <textarea name="compromisos" placeholder="Acuerdos, plazos y condiciones del compromiso..."></textarea>
                    </div>
                    <div class="form-group full">
                        <label>Soporte / documento (PDF, imagen)</label>
                        <input type="file" name="soporte_atencion" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    </div>
                </div>

                <!-- FIRMAS DIGITALES -->
                <div style="margin-top:18px;border-top:1px solid var(--gris-border);padding-top:16px">
                    <div style="font-family:'Nunito',sans-serif;font-weight:800;font-size:14px;margin-bottom:12px;color:var(--negro)">
                        âœ Firmas digitales
                        <span style="font-size:11px;font-weight:400;color:var(--gris-text);margin-left:8px">Firma con el dedo o el mouse en cada recuadro</span>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
                        <div class="firma-wrap">
                            <div class="firma-label">Instructor <button type="button" onclick="limpiarFirma('cvInstructor','firma_instructor')" style="background:none;border:none;cursor:pointer;font-size:10px;color:var(--rojo)">Limpiar</button></div>
                            <canvas id="cvInstructor" class="firma-canvas"></canvas>
                            <input type="hidden" name="firma_instructor" id="firma_instructor">
                        </div>
                        <div class="firma-wrap">
                            <div class="firma-label">Aprendiz <button type="button" onclick="limpiarFirma('cvAprendiz','firma_aprendiz')" style="background:none;border:none;cursor:pointer;font-size:10px;color:var(--rojo)">Limpiar</button></div>
                            <canvas id="cvAprendiz" class="firma-canvas"></canvas>
                            <input type="hidden" name="firma_aprendiz" id="firma_aprendiz">
                        </div>
                        <div class="firma-wrap">
                            <div class="firma-label">Gestor <button type="button" onclick="limpiarFirma('cvGestor','firma_gestor')" style="background:none;border:none;cursor:pointer;font-size:10px;color:var(--rojo)">Limpiar</button></div>
                            <canvas id="cvGestor" class="firma-canvas"></canvas>
                            <input type="hidden" name="firma_gestor" id="firma_gestor">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalAtencion')">Cancelar</button>
                <button type="submit" class="btn btn-primary" onclick="capturarFirmas()">Guardar atenciÃ³n</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL COMITÃ‰ -->
<div class="modal-overlay" id="modalComiteDisc">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <div class="modal-title">Remitir a ComitÃ© Disciplinario</div>
            <button class="modal-close" onclick="closeModal('modalComiteDisc')">âœ•</button>
        </div>
        <form method="POST">
            <input type="hidden" name="form" value="comite">
            <input type="hidden" name="hecho_id" id="comite_hecho_id">
            <input type="hidden" name="aprendiz_id" id="comite_aprendiz_id">
            <div class="modal-body">
                <div class="alert alert-error">âš ï¸ Esta acciÃ³n remite el caso al comitÃ©. Use solo cuando se han agotado las instancias disciplinarias internas.</div>
                <div id="comite_aprendiz_nombre" style="font-family:'Nunito',sans-serif;font-weight:800;font-size:15px;margin-bottom:14px;color:var(--negro)"></div>
                <div class="form-group">
                    <label>Motivo de remisiÃ³n *</label>
                    <textarea name="motivo_comite" required rows="4" placeholder="Detalle los hechos, atenciones realizadas, reincidencias y por quÃ© se remite a comitÃ©..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalComiteDisc')">Cancelar</button>
                <button type="submit" class="btn btn-danger">Remitir a comitÃ©</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL VER FIRMA -->
<div class="modal-overlay" id="modalVerFirma">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title" id="tituloVerFirma">Firma</div>
            <button class="modal-close" onclick="closeModal('modalVerFirma')">âœ•</button>
        </div>
        <div class="modal-body" style="text-align:center">
            <img id="imgVerFirma" src="" style="max-width:100%;border:1px solid var(--gris-border);border-radius:8px">
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('modalVerFirma')">Cerrar</button></div>
    </div>
</div>

<script>
// â”€â”€ Tabs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function switchTab(t) {
    document.querySelectorAll('.disc-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.disc-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + t).classList.add('active');
    event.target.classList.add('active');
}

// â”€â”€ Editar hecho â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function editarHecho(h) {
    document.getElementById('titleHecho').textContent = 'Editar hecho disciplinario';
    document.getElementById('hecho_edit_id').value  = h.id;
    document.getElementById('hecho_aprendiz').value = h.aprendiz_id;
    document.getElementById('hecho_ficha_id').value = h.ficha_id || '';
    document.getElementById('hecho_fecha').value    = h.fecha_hecho;
    document.getElementById('hecho_tipo').value     = h.tipo_hecho;
    document.getElementById('hecho_lugar').value    = h.lugar || '';
    document.getElementById('hecho_estado').value   = h.estado;
    document.getElementById('hecho_desc').value     = h.descripcion || '';
    document.getElementById('hecho_testigos').value = h.testigos || '';
    openModal('modalHecho');
}

// Autocompletar ficha al seleccionar aprendiz
document.getElementById('hecho_aprendiz').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('hecho_ficha_id').value = opt.dataset.ficha || '';
});

// â”€â”€ Preparar atenciÃ³n â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function prepararAtencion(hechoId, aprendizId, nombre) {
    document.getElementById('at_hecho').value = hechoId;
    document.getElementById('at_aprendiz_id').value = aprendizId;
    openModal('modalAtencion');
    setTimeout(() => { initFirmas(); }, 200);
}
function cargarAprendizAt(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('at_aprendiz_id').value = opt.dataset.aprendiz || '';
}
function cargarAprendizSeg(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('seg_aprendiz_id').value = opt.dataset.aprendiz || '';
}

// â”€â”€ ComitÃ© â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function prepararComite(hechoId, aprendizId, nombre) {
    document.getElementById('comite_hecho_id').value   = hechoId;
    document.getElementById('comite_aprendiz_id').value = aprendizId;
    document.getElementById('comite_aprendiz_nombre').textContent = 'Aprendiz: ' + nombre;
    openModal('modalComiteDisc');
}

// â”€â”€ Ver firma â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function verFirma(src, quien) {
    document.getElementById('tituloVerFirma').textContent = 'Firma â€” ' + quien;
    document.getElementById('imgVerFirma').src = src;
    openModal('modalVerFirma');
}

// â”€â”€ Firmas digitales â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
let firmasIniciadas = false;
function initFirmas() {
    if (firmasIniciadas) return;
    firmasIniciadas = true;
    ['cvInstructor','cvAprendiz','cvGestor'].forEach(id => setupFirma(id));
}

function setupFirma(canvasId) {
    const c = document.getElementById(canvasId);
    if (!c) return;
    // Ajustar tamaÃ±o al contenedor
    c.width  = c.offsetWidth  || 280;
    c.height = c.offsetHeight || 90;
    const ctx = c.getContext('2d');
    ctx.strokeStyle = '#1a2e22';
    ctx.lineWidth   = 2;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
    let drawing = false;

    function getPos(e) {
        const r = c.getBoundingClientRect();
        const src = e.touches ? e.touches[0] : e;
        return {
            x: (src.clientX - r.left) * (c.width  / r.width),
            y: (src.clientY - r.top)  * (c.height / r.height)
        };
    }
    c.addEventListener('mousedown',  e => { drawing=true; ctx.beginPath(); const p=getPos(e); ctx.moveTo(p.x,p.y); });
    c.addEventListener('mousemove',  e => { if(!drawing) return; const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); });
    c.addEventListener('mouseup',    () => drawing=false);
    c.addEventListener('mouseleave', () => drawing=false);
    c.addEventListener('touchstart', e => { e.preventDefault(); drawing=true; ctx.beginPath(); const p=getPos(e); ctx.moveTo(p.x,p.y); }, {passive:false});
    c.addEventListener('touchmove',  e => { e.preventDefault(); if(!drawing) return; const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); }, {passive:false});
    c.addEventListener('touchend',   () => drawing=false);
}

function limpiarFirma(canvasId, hiddenId) {
    const c = document.getElementById(canvasId);
    c.getContext('2d').clearRect(0, 0, c.width, c.height);
    document.getElementById(hiddenId).value = '';
}

function capturarFirmas() {
    const map = {cvInstructor:'firma_instructor', cvAprendiz:'firma_aprendiz', cvGestor:'firma_gestor'};
    Object.entries(map).forEach(([cid, hid]) => {
        const c = document.getElementById(cid);
        if (c) document.getElementById(hid).value = c.toDataURL('image/png');
    });
}

// Iniciar firmas cuando se abre el modal de atenciÃ³n
document.getElementById('modalAtencion').addEventListener('click', function init() {
    initFirmas();
    this.removeEventListener('click', init);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
