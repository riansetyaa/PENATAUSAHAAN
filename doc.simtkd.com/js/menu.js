/**
 * SIM-TKD (doc.simtkd.com) - Sidebar Terpusat
 * ============================================
 * Definisi menu sidebar modul Tanda Tangan Dokumen dipusatkan di file
 * ini agar setiap perubahan menu cukup dilakukan DI SINI dan langsung
 * berlaku merata di semua halaman doc.simtkd.com.
 *
 * Cara pakai di halaman:
 *   1. Sediakan container (boleh kosong):
 *        <aside class="sidebar"></aside>
 *   2. Muat script setelah js/config.js:
 *        <script src="js/menu.js"></script>
 *        <script src="js/sidebar.js"></script>
 *
 * Item "aktif" dideteksi otomatis dari nama file halaman sekarang.
 */
(function () {
    'use strict';

    // ==========================================================
    // DAFTAR MENU - edit di sini saja untuk update merata
    // ==========================================================
    var ITEMS = [
        { section: 'Menu' },
        { href: 'index.html',  icon: 'fa-file-lines',    label: 'Daftar Dokumen' },
        { href: 'verify.html', icon: 'fa-shield-halved', label: 'Verifikasi Dokumen' },
        { section: 'Lainnya' },
        { href: 'https://simtkd.com/dashboard.html', icon: 'fa-arrow-left', label: 'Kembali ke SIM-TKD', attrs: ' data-back-main' }
    ];

    function esc(t) {
        var d = document.createElement('div');
        d.textContent = String(t || '');
        return d.innerHTML;
    }

    function currentFile() {
        var p = String(location.pathname).split('/').pop();
        return p || 'index.html';
    }

    function render() {
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        var cur = currentFile();

        var html = ITEMS.map(function (it) {
            if (it.section) return '<div class="side-title">' + esc(it.section) + '</div>';
            var aktif = (it.href.indexOf('http') !== 0) && (it.href === cur);
            return '<a href="' + esc(it.href) + '"' + (it.attrs || '') +
                       ' class="side-link' + (aktif ? ' active' : '') + '">' +
                       '<i class="fas ' + esc(it.icon) + '"></i> ' + esc(it.label) +
                   '</a>';
        }).join('');

        sidebar.innerHTML = html;

        // Saat diuji LOKAL, arahkan "Kembali ke SIM-TKD" ke dashboard app utama
        try {
            var host = window.location.hostname;
            if (host === '127.0.0.1' || host === 'localhost') {
                var back = sidebar.querySelector('a[data-back-main]');
                if (back) back.setAttribute('href', '/dashboard.html');
            }
        } catch (e) { /* abaikan */ }
    }

    // Render sinkron: dimuat SETELAH elemen <aside> dan SEBELUM
    // js/sidebar.js & kode yang membawa ?token= pada link internal.
    render();
})();
