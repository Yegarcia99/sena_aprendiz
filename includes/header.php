<?php
// includes/header.php
require_once __DIR__ . '/notificaciones.php';
$user = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
// Calcular ruta relativa a la raiz del proyecto (funciona desde /pages/ y cualquier subdirectorio)
$depth    = substr_count(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') 
            - substr_count(str_replace('\\', '/', rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?? '', '/')), '/');
$baseUrl  = BASE_URL;  // Usar BASE_URL dinamico (calculado en database.php)

// Redirigir a foto obligatoria o cambio de contrasena en el primer ingreso
// Siempre leemos desde BD para garantizar datos frescos
if (!in_array($currentPage, ['subir_foto', 'perfil', 'mis_pendientes'])) {
    $chkUser = getDB()->prepare("SELECT debe_cambiar_pass, debe_subir_foto FROM usuarios WHERE id=? AND activo=1");
    $chkUser->execute([(int)($user['id'] ?? 0)]);
    $chkRow = $chkUser->fetch();

    if ($chkRow) {
        // Prioridad 1: foto obligatoria (primer paso)
        if ((int)$chkRow['debe_subir_foto'] === 1) {
            header('Location: ' . BASE_URL . '/pages/subir_foto.php');
            exit;
        }
        // Prioridad 2: cambio de contrasena
        if ((int)$chkRow['debe_cambiar_pass'] === 1) {
            header('Location: ' . BASE_URL . '/pages/perfil.php');
            exit;
        }
    }
}

if (!function_exists('uiIcon')) {
    function uiIcon(string $name): string {
        $icons = [
            'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
            'user' => '<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'checklist' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
            'route' => '<circle cx="6" cy="19" r="3"/><circle cx="18" cy="5" r="3"/><path d="M8.6 17.4 15.4 6.6"/>',
            'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
            'layers' => '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
            'gavel' => '<path d="m14 13-7 7"/><path d="m8 14 6-6"/><path d="m13 5 6 6"/><path d="m16 2 6 6"/><path d="M3 21h8"/>',
            'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
            'bar' => '<path d="M3 3v18h18"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-3"/>',
            'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>',
            'history' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 3v6h6"/><path d="M12 7v5l4 2"/>',
            'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
            'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
            'phone' => '<path d="M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/><path d="M12 18h.01"/>',
        ];
        $body = $icons[$name] ?? $icons['file'];
        return '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= csrfToken() ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/main.css?v=<?= filemtime(__DIR__ . '/../assets/css/main.css') ?>">
    <!-- PWA -->
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <meta name="theme-color" content="#39A900">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="SENA App">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/image/icon-192.png">
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <div class="sena-icon">
            <img class="img" src="<?= BASE_URL ?>/image/logoSena.png" alt="">
        </div>
        <div class="sena-text">
            <span class="sena-name">SENA</span>
            <span class="sena-sub">Seguimiento Aprendices</span>
        </div>
    </div>

    <?php
    $rolNav  = $user['rol'] ?? '';
    $esAprendiz    = ($rolNav === 'Aprendiz');
    $esInstructor  = ($rolNav === 'Instructor');
    $esGestor      = ($rolNav === 'Gestor');
    $esCoordOrUp   = in_array($rolNav, ['Coordinador', 'Administrador']);
    $esAdmin       = ($rolNav === 'Administrador');
    ?>
    <nav class="sidebar-nav">
        <?php if ($esAprendiz): ?>
        <!-- APRENDIZ: solo ve sus pendientes -->
        <div class="nav-section-title">Mi Cuenta</div>
        <a href="<?= BASE_URL ?>/pages/mis_pendientes.php" class="nav-item <?= $currentPage === 'mis_pendientes' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('checklist') ?></span>
            <span>Mis Pendientes</span>
        </a>
        <?php else: ?>
        <!-- OTROS ROLES: menu completo segun rol -->
        <div class="nav-section-title">Principal</div>
        <a href="<?= BASE_URL ?>/pages/dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('home') ?></span>
            <span>Dashboard</span>
        </a>

        <div class="nav-section-title academico">Gestion Academica</div>
        <a href="<?= BASE_URL ?>/pages/aprendices.php" class="nav-item <?= $currentPage === 'aprendices' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('users') ?></span>
            <span>Aprendices</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/pendientes.php" class="nav-item <?= $currentPage === 'pendientes' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('checklist') ?></span>
            <span>Pendientes</span>
        </a>
        <?php if (!$esInstructor): ?>
        <a href="<?= BASE_URL ?>/pages/asistente_caso.php" class="nav-item <?= $currentPage === 'asistente_caso' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('route') ?></span>
            <span>Registro Avanzado</span>
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/pages/acciones.php" class="nav-item <?= $currentPage === 'acciones' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('refresh') ?></span>
            <span>Acciones Remediales</span>
        </a>
        <?php if (!$esInstructor): ?>
        <a href="<?= BASE_URL ?>/pages/seguimiento_academico.php" class="nav-item <?= $currentPage === 'seguimiento_academico' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('layers') ?></span>
            <span>Instancias</span>
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/pages/expediente.php" class="nav-item <?= $currentPage === 'expediente' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('file') ?></span>
            <span>Expediente</span>
        </a>
        <?php if (!$esInstructor): ?>
        <a href="<?= BASE_URL ?>/pages/comite.php" class="nav-item <?= $currentPage === 'comite' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('gavel') ?></span>
            <span>Comite</span>
        </a>
        <?php endif; ?>

        <?php if (!$esAprendiz): ?>
        <div class="nav-section-title disciplinario">Disciplinario</div>
        <?php if (!$esInstructor): ?>
        <a href="<?= BASE_URL ?>/pages/asistente_disciplinario.php" class="nav-item <?= $currentPage === 'asistente_disciplinario' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('route') ?></span>
            <span>Asistente Disciplinario</span>
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/pages/disciplinario.php" class="nav-item <?= $currentPage === 'disciplinario' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('alert') ?></span>
            <span>Seguimiento Disc.</span>
        </a>
        <?php endif; ?>

        <?php if ($esCoordOrUp): ?>
        <div class="nav-section-title">Configuracion</div>
        <a href="<?= BASE_URL ?>/pages/instructores.php" class="nav-item <?= $currentPage === 'instructores' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('user') ?></span>
            <span>Instructores</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/competencias.php" class="nav-item <?= $currentPage === 'competencias' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('book') ?></span>
            <span>Competencias</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/fichas.php" class="nav-item <?= $currentPage === 'fichas' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('grid') ?></span>
            <span>Fichas / Grupos</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/codigos_barras.php" class="nav-item <?= $currentPage === 'codigos_barras' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('bar') ?></span>
            <span>Analitica de Fichas</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/reportes.php" class="nav-item <?= $currentPage === 'reportes' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('file') ?></span>
            <span>Reportes</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/auditoria.php" class="nav-item <?= $currentPage === 'auditoria' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('history') ?></span>
            <span>Auditoria</span>
        </a>
        <?php endif; ?>
        <?php if ($esAdmin): ?>
        <a href="<?= BASE_URL ?>/pages/gestion_usuarios.php" class="nav-item <?= $currentPage === 'gestion_usuarios' ? 'active' : '' ?>">
            <span class="nav-icon"><?= uiIcon('users') ?></span>
            <span>Gestion Usuarios</span>
        </a>
        <?php endif; ?>
        <?php endif; // fin !esAprendiz ?>

    </nav>
</aside>

<!-- MAIN CONTENT -->
<main class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()"><?= uiIcon('menu') ?></button>
        <div class="topbar-title"><?= $pageTitle ?? APP_NAME ?></div>
        <div class="topbar-right">
            <div class="topbar-divider"></div>
            <!-- Menu de usuario -->
            <div class="user-menu-wrap" style="position:relative">
                <button class="user-menu-btn" onclick="toggleUserMenu()" title="Mi cuenta">
                    <?php $fotoSesion = $user['foto'] ?? null; ?>
                    <?php if ($fotoSesion): ?>
                    <img src="<?= BASE_URL ?>/uploads/fotos_usuarios/<?= htmlspecialchars($fotoSesion, ENT_QUOTES, 'UTF-8') ?>"
                         style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.4)"
                         alt="Foto perfil">
                    <?php else: ?>
                    <div class="user-avatar"><?= strtoupper(substr($user['nombres'] ?? 'U', 0, 1)) ?></div>
                    <?php endif; ?>
                    <div class="user-menu-info">
                        <div class="user-name"><?= sanitize($user['nombres'] ?? '') ?></div>
                        <div class="user-role"><?= sanitize($user['rol'] ?? '') ?></div>
                    </div>
                    <svg class="user-menu-chevron" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div id="panelUserMenu" class="user-menu-panel" style="display:none">
                    <div class="user-menu-header">
                        <?php if ($fotoSesion): ?>
                        <img src="<?= BASE_URL ?>/uploads/fotos_usuarios/<?= htmlspecialchars($fotoSesion, ENT_QUOTES, 'UTF-8') ?>"
                             style="width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid #e0e0e0"
                             alt="Foto perfil">
                        <?php else: ?>
                        <div class="user-avatar" style="width:34px;height:34px;font-size:14px"><?= strtoupper(substr($user['nombres'] ?? 'U', 0, 1)) ?></div>
                        <?php endif; ?>
                        <div>
                            <div style="font-size:13px;font-weight:600;color:var(--ink)"><?= sanitize($user['nombres'] ?? '') ?></div>
                            <div style="font-size:11px;color:var(--muted)"><?= sanitize($user['rol'] ?? '') ?></div>
                        </div>
                    </div>
                    <div class="user-menu-divider"></div>
                    <a href="<?= BASE_URL ?>/pages/notificaciones.php" class="user-menu-item">
                        <span class="user-menu-icon"><?= uiIcon('bell') ?></span> Notificaciones
                        <?php if (!empty($notifCount) && $notifCount > 0): ?>
                        <span class="user-menu-badge"><?= min($notifCount, 99) ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= BASE_URL ?>/pages/perfil.php" class="user-menu-item">
                        <span class="user-menu-icon"><?= uiIcon('user') ?></span> Mi Perfil
                    </a>
                    <?php if ($esAdmin): ?>
                    <a href="<?= BASE_URL ?>/pages/gestion_usuarios.php" class="user-menu-item">
                        <span class="user-menu-icon"><?= uiIcon('users') ?></span> Gestion de Usuarios
                    </a>
                    <?php endif; ?>
                    <div class="user-menu-divider"></div>
                    <form method="POST" action="<?= BASE_URL ?>/logout.php" style="margin:0;padding:6px 8px">
                        <?= csrfField() ?>
                        <button type="submit" class="user-menu-logout">Salir</button>
                    </form>
                </div>
            </div>
            <!-- Boton instalar PWA: solo visible cuando el navegador lo soporta -->
            <button id="btnPwaInstall" onclick="pwaInstall()"
                title="Instalar app en tu dispositivo"
                style="display:none;background:none;border:none;cursor:pointer;font-size:20px;padding:4px 8px;color:var(--verde-dark);line-height:1;opacity:.85;transition:.2s"
                onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity=.85">
                <?= uiIcon('phone') ?>
            </button>
            <?php
            $db = getDB();
            if (!function_exists('ensureExpedienteSchema')) {
                require_once __DIR__ . '/expediente_schema.php';
            }
            if (!function_exists('ensureDisciplinarioSchema')) {
                require_once __DIR__ . '/disciplinario_schema.php';
            }
            ensureExpedienteSchema($db);
            ensureDisciplinarioSchema($db);
            $notifCount = contarAlertasNuevas($db);
            $notifLista = getAlertasPendientes($db);
            ?>
            <div style="position:relative">
                <button id="btnCampanita" onclick="toggleCampanita()"
                    style="position:relative;background:none;border:none;cursor:pointer;font-size:20px;padding:4px 8px;color:var(--verde-dark);line-height:1">
                    <?= uiIcon('bell') ?>
                    <?php if ($notifCount > 0): ?>
                    <span id="campBadge" style="position:absolute;top:-1px;right:-1px;background:#e74c3c;color:#fff;font-size:9px;font-weight:800;border-radius:50%;width:16px;height:16px;display:flex;align-items:center;justify-content:center;font-family:'Nunito',sans-serif">
                        <?= min($notifCount, 99) ?>
                    </span>
                    <?php endif; ?>
                </button>
                <div id="panelCampanita" style="display:none;position:absolute;right:0;top:46px;width:340px;background:#fff;border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.15);z-index:300;border:1.5px solid var(--gris-border);overflow:hidden">
                    <div style="padding:12px 16px;border-bottom:1px solid var(--gris-border);display:flex;justify-content:space-between;align-items:center;background:var(--verde-pale)">
                        <span style="font-family:'Nunito',sans-serif;font-weight:800;font-size:13px;color:var(--verde-dark)">Notificaciones</span>
                        <?php if ($notifCount > 0): ?>
                        <button onclick="marcarTodasLeidas()" style="background:none;border:none;cursor:pointer;font-size:11px;color:var(--verde);font-weight:700">Marcar todas leidas</button>
                        <?php endif; ?>
                    </div>
                    <div style="max-height:320px;overflow-y:auto">
                    <?php if (empty($notifLista)): ?>
                        <div style="padding:28px;text-align:center;color:var(--gris-text);font-size:13px">Sin notificaciones nuevas</div>
                    <?php else: ?>
                        <?php foreach ($notifLista as $n): ?>
                        <div class="camp-item" id="camp-<?= $n['id'] ?>"
                             style="padding:11px 16px;border-bottom:1px solid var(--gris-border);cursor:pointer;<?= $n['estado_envio']==='Registrada' ? 'background:#fffdf0;border-left:3px solid #f39c12' : '' ?>"
                             onmouseenter="this.style.background='var(--verde-pale)'"
                             onmouseleave="this.style.background='<?= $n['estado_envio']==='Registrada' ? '#fffdf0' : '#fff' ?>'"
                             onclick="marcarLeida(<?= $n['id'] ?>,this)">
                            <div style="font-size:11px;font-weight:700;color:var(--negro)"><?= sanitize($n['asunto']) ?></div>
                            <div style="font-size:10px;color:var(--gris-text);margin-top:2px;line-height:1.4">
                                <?= $n['aprendiz_nombre'] ? sanitize($n['aprendiz_nombre']).' - ' : '' ?>
                                <?= sanitize(substr($n['mensaje'], 0, 80)) ?>...
                            </div>
                            <div style="font-size:9px;color:#aaa;margin-top:3px"><?= date('d/m/Y H:i', strtotime($n['fecha_envio'])) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                    <div style="padding:9px 16px;text-align:center;border-top:1px solid var(--gris-border)">
                        <a href="<?= BASE_URL ?>/pages/notificaciones.php" style="font-size:11px;color:var(--verde);font-weight:700;text-decoration:none">Ver todas &rarr;</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="content-area">
<script>

function toggleUserMenu() {
    const p = document.getElementById('panelUserMenu');
    p.style.display = p.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
    const wrap = document.querySelector('.user-menu-wrap');
    const panel = document.getElementById('panelUserMenu');
    if (panel && wrap && !wrap.contains(e.target)) {
        panel.style.display = 'none';
    }
});
function toggleSidebar() {
    const s = document.getElementById('sidebar');
    s.classList.toggle('open');
    document.body.classList.toggle('sidebar-open', s.classList.contains('open'));
}
// Cerrar sidebar al hacer click en el overlay (movil)
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.querySelector('.sidebar-toggle');
    if (sidebar && sidebar.classList.contains('open') && window.innerWidth <= 768) {
        if (!sidebar.contains(e.target) && toggle && !toggle.contains(e.target)) {
            sidebar.classList.remove('open');
            document.body.classList.remove('sidebar-open');
        }
    }
});
function toggleCampanita() {
    const p = document.getElementById('panelCampanita');
    p.style.display = p.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
    const btn = document.getElementById('btnCampanita');
    const panel = document.getElementById('panelCampanita');
    if (panel && btn && !btn.contains(e.target) && !panel.contains(e.target)) {
        panel.style.display = 'none';
    }
});
function marcarLeida(id, el) {
    fetch('<?= BASE_URL ?>/ajax/marcar_leida.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + id
    });
    el.style.opacity = '0.45';
    el.style.borderLeft = 'none';
    const badge = document.getElementById('campBadge');
    if (badge) {
        const c = parseInt(badge.textContent) - 1;
        if (c <= 0) badge.style.display = 'none';
        else badge.textContent = c;
    }
}
function marcarTodasLeidas() {
    fetch('<?= BASE_URL ?>/ajax/marcar_leida.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'todo=1'
    });
    document.querySelectorAll('.camp-item').forEach(n => {
        n.style.opacity = '0.45';
        n.style.borderLeft = 'none';
        n.style.background = '#fff';
    });
    const badge = document.getElementById('campBadge');
    if (badge) badge.style.display = 'none';
}

// PWA: Service Worker + boton de instalacion
if ('serviceWorker' in navigator) {
    if (location.hostname === 'localhost' || location.hostname === '127.0.0.1') {
        navigator.serviceWorker.getRegistrations()
            .then(registrations => registrations.forEach(registration => registration.unregister()))
            .catch(() => {});
    } else {
        navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js?v=3').catch(() => {});
    }
}
let deferredPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredPrompt = e;
    const btn = document.getElementById('btnPwaInstall');
    if (btn) btn.style.display = 'inline-block';
});
function pwaDismiss() {}
function pwaInstall() {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(r => {
        deferredPrompt = null;
        const btn = document.getElementById('btnPwaInstall');
        if (btn) btn.style.display = 'none';
    });
}
window.addEventListener('appinstalled', () => {
    const btn = document.getElementById('btnPwaInstall');
    if (btn) btn.style.display = 'none';
});
</script>
