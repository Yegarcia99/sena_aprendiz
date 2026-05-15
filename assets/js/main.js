// assets/js/main.js - SENA Seguimiento Aprendices

// ── Modal Helpers ──────────────────────────────────────────
function openModal(id) {
    document.getElementById(id).classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

// ── Confirm Delete ─────────────────────────────────────────
function confirmDelete(url, name) {
    if (confirm(`¿Está seguro de eliminar "${name}"?\nEsta acción no se puede deshacer.`)) {
        window.location.href = url;
    }
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

// ── Auto-hide alerts ───────────────────────────────────────
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        alert.style.transition = 'opacity .5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    }, 4000);
});

// ── Sidebar overlay close on mobile ───────────────────────
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggle  = document.querySelector('.sidebar-toggle');
    if (sidebar && toggle && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
        sidebar.classList.remove('open');
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
