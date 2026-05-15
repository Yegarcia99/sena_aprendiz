<?php
// includes/header.php
require_once __DIR__ . '/notificaciones.php';
$user = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
// Calcular ruta relativa a la raíz del proyecto (funciona desde /pages/ y cualquier subdirectorio)
$depth    = substr_count(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') 
            - substr_count(str_replace('\\', '/', rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?? '', '/')), '/');
$baseUrl  = BASE_URL;  // Usar BASE_URL dinámico (calculado en database.php)

// Redirigir a cambio de contraseña si es primer ingreso (excepto la misma página de perfil)
if ($currentPage !== 'perfil') {
    $chkPass = getDB()->prepare("SELECT debe_cambiar_pass FROM usuarios WHERE id=?");
    $chkPass->execute([$user['id'] ?? 0]);
    if ($chkPass->fetchColumn()) {
        header('Location: ' . BASE_URL . '/pages/perfil.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800&family=Nunito+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/main.css">
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

    <nav class="sidebar-nav">
        <div class="nav-section-title">Principal</div>
        <a href="<?= BASE_URL ?>/pages/dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <span class="nav-icon">◈</span>
            <span>Dashboard</span>
        </a>

        <div class="nav-section-title academico">🎓 Gestión Académica</div>
        <a href="<?= BASE_URL ?>/pages/aprendices.php" class="nav-item <?= $currentPage === 'aprendices' ? 'active' : '' ?>">
            <span class="nav-icon">◉</span>
            <span>Aprendices</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/pendientes.php" class="nav-item <?= $currentPage === 'pendientes' ? 'active' : '' ?>">
            <span class="nav-icon">◎</span>
            <span>Pendientes</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/asistente_caso.php" class="nav-item <?= $currentPage === 'asistente_caso' ? 'active' : '' ?>">
            <span class="nav-icon">▶</span>
            <span>Asistente de Caso</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/acciones.php" class="nav-item <?= $currentPage === 'acciones' ? 'active' : '' ?>">
            <span class="nav-icon">◐</span>
            <span>Acciones Remediales</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/expediente.php" class="nav-item <?= $currentPage === 'expediente' ? 'active' : '' ?>">
            <span class="nav-icon">▣</span>
            <span>Expediente</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/comite.php" class="nav-item <?= $currentPage === 'comite' ? 'active' : '' ?>">
            <span class="nav-icon">◑</span>
            <span>Comité</span>
        </a>

        <div class="nav-section-title">Configuración</div>
        <a href="<?= BASE_URL ?>/pages/instructores.php" class="nav-item <?= $currentPage === 'instructores' ? 'active' : '' ?>">
            <span class="nav-icon">◇</span>
            <span>Instructores</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/competencias.php" class="nav-item <?= $currentPage === 'competencias' ? 'active' : '' ?>">
            <span class="nav-icon">📚</span>
            <span>Competencias</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/fichas.php" class="nav-item <?= $currentPage === 'fichas' ? 'active' : '' ?>">
            <span class="nav-icon">◆</span>
            <span>Fichas / Grupos</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/codigos_barras.php" class="nav-item <?= $currentPage === 'codigos_barras' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span>
            <span>Analítica de Fichas</span>
        </a>
        <?php if (hasRole(['Administrador','Coordinador'])): ?>
        <a href="<?= BASE_URL ?>/pages/reportes.php" class="nav-item <?= $currentPage === 'reportes' ? 'active' : '' ?>">
            <span class="nav-icon">▣</span>
            <span>Reportes</span>
        </a>
        <?php endif; ?>

        <div class="nav-section-title disciplinario">⚠️ Disciplinario</div>
        <a href="<?= BASE_URL ?>/pages/disciplinario.php" class="nav-item <?= $currentPage === 'disciplinario' ? 'active' : '' ?>">
            <span class="nav-icon">⚠</span>
            <span>Seguimiento Disc.</span>
        </a>
        <div class="nav-section-title">Mi Cuenta</div>
        <a href="<?= BASE_URL ?>/pages/notificaciones.php" class="nav-item <?= $currentPage === 'notificaciones' ? 'active' : '' ?>">
            <span class="nav-icon">🔔</span>
            <span>Notificaciones</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/perfil.php" class="nav-item <?= $currentPage === 'perfil' ? 'active' : '' ?>">
            <span class="nav-icon">🔐</span>
            <span>Mi Perfil</span>
        </a>
        <?php if (hasRole(['Administrador'])): ?>
        <a href="<?= BASE_URL ?>/pages/gestion_usuarios.php" class="nav-item <?= $currentPage === 'gestion_usuarios' ? 'active' : '' ?>">
            <span class="nav-icon">👥</span>
            <span>Gestión de Usuarios</span>
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($user['nombres'] ?? 'U', 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= sanitize($user['nombres'] ?? '') ?></div>
                <div class="user-role"><?= sanitize($user['rol'] ?? '') ?></div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/pages/perfil.php" style="font-size:11px;color:var(--verde);text-decoration:none;display:block;margin-bottom:4px">🔐 Mi Perfil</a>
        <a href="<?= BASE_URL ?>/logout.php" class="logout-btn">Salir</a>
    </div>
</aside>

<!-- MAIN CONTENT -->
<main class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>
        <div class="topbar-title"><?= $pageTitle ?? APP_NAME ?></div>
        <div class="topbar-right">
            <span class="badge-fecha"><?= date('d/m/Y H:i') ?></span>
            <!-- Botón instalar PWA — solo visible cuando el navegador lo soporta -->
            <button id="btnPwaInstall" onclick="pwaInstall()"
                title="Instalar app en tu dispositivo"
                style="display:none;background:none;border:none;cursor:pointer;font-size:20px;padding:4px 8px;color:var(--verde-dark);line-height:1;opacity:.85;transition:.2s"
                onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity=.85">
                📲
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
                    🔔
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
                        <button onclick="marcarTodasLeidas()" style="background:none;border:none;cursor:pointer;font-size:11px;color:var(--verde);font-weight:700">✓ Marcar todas leídas</button>
                        <?php endif; ?>
                    </div>
                    <div style="max-height:320px;overflow-y:auto">
                    <?php if (empty($notifLista)): ?>
                        <div style="padding:28px;text-align:center;color:var(--gris-text);font-size:13px">Sin notificaciones nuevas ✅</div>
                    <?php else: ?>
                        <?php foreach ($notifLista as $n): ?>
                        <div class="camp-item" id="camp-<?= $n['id'] ?>"
                             style="padding:11px 16px;border-bottom:1px solid var(--gris-border);cursor:pointer;<?= $n['estado_envio']==='Registrada' ? 'background:#fffdf0;border-left:3px solid #f39c12' : '' ?>"
                             onmouseenter="this.style.background='var(--verde-pale)'"
                             onmouseleave="this.style.background='<?= $n['estado_envio']==='Registrada' ? '#fffdf0' : '#fff' ?>'"
                             onclick="marcarLeida(<?= $n['id'] ?>,this)">
                            <div style="font-size:11px;font-weight:700;color:var(--negro)"><?= sanitize($n['asunto']) ?></div>
                            <div style="font-size:10px;color:var(--gris-text);margin-top:2px;line-height:1.4">
                                <?= $n['aprendiz_nombre'] ? sanitize($n['aprendiz_nombre']).' — ' : '' ?>
                                <?= sanitize(substr($n['mensaje'], 0, 80)) ?>...
                            </div>
                            <div style="font-size:9px;color:#aaa;margin-top:3px"><?= date('d/m/Y H:i', strtotime($n['fecha_envio'])) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                    <div style="padding:9px 16px;text-align:center;border-top:1px solid var(--gris-border)">
                        <a href="<?= BASE_URL ?>/pages/notificaciones.php" style="font-size:11px;color:var(--verde);font-weight:700;text-decoration:none">Ver todas →</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="content-area">
<script>

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

// ── PWA: Service Worker + botón de instalación ──────────────
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js').catch(() => {});
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
