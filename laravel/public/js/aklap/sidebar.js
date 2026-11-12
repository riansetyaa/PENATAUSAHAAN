/**
 * SIM-TKD - Sidebar Toggle (shared)
 * ============================================
 * Menyuntikkan tombol hamburger di .topbar-left dan
 * mengatur minify/maximize sidebar (.sidebar.collapsed).
 * Status tersimpan di localStorage per subdomain.
 */
(function () {
    'use strict';

    function init() {
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        // Cari tombol yang sudah ada; kalau belum ada, buat otomatis
        var btn = document.getElementById('sidebarToggle');
        if (!btn) {
            var left = document.querySelector('.topbar-left');
            if (!left) return;
            btn = document.createElement('button');
            btn.id = 'sidebarToggle';
            btn.type = 'button';
            btn.className = 'sidebar-toggle';
            btn.setAttribute('aria-label', 'Perkecil/perbesar menu');
            btn.innerHTML = '<i class="fas fa-bars"></i>';
            left.insertBefore(btn, left.firstChild);
        }

        // Pulihkan status terakhir
        var key = 'simtkd_sidebar_' + location.hostname;
        try {
            if (localStorage.getItem(key) === 'collapsed') {
                sidebar.classList.add('collapsed');
            }
        } catch (e) { /* localStorage tidak tersedia */ }

        btn.addEventListener('click', function () {
            var collapsed = sidebar.classList.toggle('collapsed');
            // Ikon berubah: bars <-> bars-staggered
            btn.innerHTML = collapsed
                ? '<i class="fas fa-bars-staggered"></i>'
                : '<i class="fas fa-bars"></i>';
            try {
                localStorage.setItem(key, collapsed ? 'collapsed' : 'open');
            } catch (e) { /* abaikan */ }
        });

        // Sinkronkan ikon saat halaman dimuat dengan status tersimpan
        if (sidebar.classList.contains('collapsed')) {
            btn.innerHTML = '<i class="fas fa-bars-staggered"></i>';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
