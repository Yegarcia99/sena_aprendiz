// assets/js/main.js - SENA Seguimiento Aprendices

// ── Modal Helpers ──────────────────────────────────────────
function openModal(id) {
    document.getElementById(id).classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

// ── Modal de confirmacion de borrado (reemplaza confirm() nativo) ──
(function() {
    // Inyectar modal en el DOM al cargar
    const tpl = `
    <div id="__deleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
        <div style="background:#fff;border-radius:14px;padding:28px 28px 22px;width:100%;max-width:380px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,.2);font-family:'Nunito',sans-serif">
            <div style="font-size:32px;text-align:center;margin-bottom:10px">🗑️</div>
            <div style="font-weight:800;font-size:16px;text-align:center;margin-bottom:8px;color:#222">¿Eliminar registro?</div>
            <div id="__deleteModalName" style="font-size:13px;color:#666;text-align:center;margin-bottom:20px;padding:8px 12px;background:#f8f8f8;border-radius:8px"></div>
            <div style="font-size:12px;color:#e53935;text-align:center;margin-bottom:20px">⚠️ Esta acción no se puede deshacer.</div>
            <div style="display:flex;gap:10px">
                <button id="__deleteModalCancel"
                    style="flex:1;padding:10px;border:1px solid #ddd;border-radius:8px;background:#fff;cursor:pointer;font-family:'Nunito',sans-serif;font-weight:700;font-size:13px">
                    Cancelar
                </button>
                <form id="__deleteModalForm" method="POST" style="flex:1;margin:0">
                    <input type="hidden" name="csrf_token" id="__deleteModalCsrf">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit"
                        style="width:100%;padding:10px;border:none;border-radius:8px;background:#e53935;color:#fff;cursor:pointer;font-family:'Nunito',sans-serif;font-weight:800;font-size:13px">
                        Sí, eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', tpl);

    document.getElementById('__deleteModalCancel').addEventListener('click', function() {
        document.getElementById('__deleteModal').style.display = 'none';
    });
    document.getElementById('__deleteModal').addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
})();

/**
 * confirmDelete(url, name)
 * Abre el modal de confirmación. Al confirmar envía POST a la url con token CSRF.
 * La URL debe ser la misma que antes (ej: "aprendices.php?action=delete&id=5")
 */
function confirmDelete(url, name) {
    const modal  = document.getElementById('__deleteModal');
    const form   = document.getElementById('__deleteModalForm');
    const nameEl = document.getElementById('__deleteModalName');
    const csrfEl = document.getElementById('__deleteModalCsrf');

    form.action  = url;
    nameEl.textContent = name;

    // Leer el token CSRF del meta tag que inyecta header.php
    const meta = document.querySelector('meta[name="csrf-token"]');
    csrfEl.value = meta ? meta.getAttribute('content') : '';

    modal.style.display = 'flex';
}

// ── Search Filter (client-side) ────────────────────────────
const searchInput = document.getElementById('tableSearch');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const term = this.value.toLowerCase();
        document.querySelectorAll('table tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(term) ? '' : 'none';
        });
    });
}

// ── Auto-hide alerts (4 segundos) ─────────────────────────
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        alert.style.transition = 'opacity .5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    }, 4000);
});

// ── Sidebar toggle + overlay en movil ─────────────────────
function toggleSidebar() {
    const s = document.getElementById('sidebar');
    if (!s) return;
    s.classList.toggle('open');
    document.body.classList.toggle('sidebar-open', s.classList.contains('open'));
}
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggle  = document.querySelector('.sidebar-toggle');
    if (sidebar && sidebar.classList.contains('open') && window.innerWidth <= 768) {
        if (!sidebar.contains(e.target) && toggle && !toggle.contains(e.target)) {
            sidebar.classList.remove('open');
            document.body.classList.remove('sidebar-open');
        }
    }
});

// ── Dynamic select: filter resultados by competencia ───────
const selCompetencia = document.getElementById('competencia_id');
const selResultado   = document.getElementById('resultado_id');
if (selCompetencia && selResultado) {
    selCompetencia.addEventListener('change', function() {
        const cid = this.value;
        if (!cid) return;
        fetch(`ajax/resultados.php?competencia_id=${cid}`)
            .then(r => r.json())
            .then(data => {
                selResultado.innerHTML = '<option value="">-- Seleccionar --</option>';
                data.forEach(r => {
                    selResultado.innerHTML += `<option value="${r.id}">${r.nombre}</option>`;
                });
            });
    });
}


// ── Auto-inyectar token CSRF en todos los formularios POST ──
(function() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;
    const token = meta.getAttribute('content');
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(form => {
        if (!form.querySelector('input[name="csrf_token"]')) {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'csrf_token';
            inp.value = token;
            form.appendChild(inp);
        }
    });
})();
