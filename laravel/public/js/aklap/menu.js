/**
 * SIM-TKD (peta.simtkd.com) - Sidebar Terpusat
 * ============================================
 * Definisi menu sidebar dipusatkan di file ini agar setiap perubahan
 * menu cukup dilakukan DI SINI dan langsung berlaku merata di semua
 * halaman AKLAP (Dashboard, Jurnal, Buku Besar, dst).
 *
 * Cara pakai di halaman:
 *   1. Sediakan container (boleh kosong):
 *        <aside class="sidebar" id="sidebar"></aside>
 *   2. Muat script SEBELUM script inline halaman (setelah config.js):
 *        <script src="js/menu.js"></script>
 *        <script src="js/sidebar.js"></script>
 *
 * Item "aktif" dideteksi otomatis dari nama file halaman sekarang.
 * Item dengan properti `sub` dirender sebagai DROPDOWN (bisa dibuka/tutup)
 * dan otomatis terbuka bila halaman aktif ada di dalamnya — mis. "Buku Kas"
 * (Buku Kas Penerimaan, Pengeluaran, BUD, Laporan Kas Harian, Rekonsiliasi Bank)
 * dan "Laporan Keuangan" (Neraca & LRA).
 * Link internal otomatis dibawa token oleh js/config.js (SIMTKD_API.init /
 * decorate).
 */
(function () {
    'use strict';

    // ==========================================================
    // DAFTAR MENU - edit di sini saja untuk update merata
    // ==========================================================
    var ITEMS = [
        { href: 'index.html',        icon: 'fa-th-large',       label: 'Dashboard' },
        { href: 'jurnal-umum.html',  icon: 'fa-book',           label: 'Jurnal Umum' },
        { href: 'jurnal-khusus.html', icon: 'fa-file-invoice-dollar', label: 'Buku Jurnal Khusus' },
        { href: 'jurnal-khusus-konsolidator.html', icon: 'fa-layer-group', label: 'Jurnal Khusus Konsolidator' },
        { href: 'buku-besar.html',   icon: 'fa-table-list',     label: 'Buku Besar' },
        { href: 'neraca-saldo.html', icon: 'fa-scale-balanced', label: 'Neraca Saldo' },
        // Dropdown "Buku Kas": buku kas penerimaan & pengeluaran PPKD, BKU (BUD),
        // laporan posisi kas harian, dan rekonsiliasi bank (semua input manual).
        { key: 'BukuKas', icon: 'fa-cash-register', label: 'Buku Kas', sub: [
            { href: 'buku-penerimaan-ppkd.html',  icon: 'fa-money-bill-wave',     label: 'Buku Kas Penerimaan' },
            { href: 'buku-pengeluaran-ppkd.html', icon: 'fa-money-bill-transfer', label: 'Buku Kas Pengeluaran' },
            { href: 'buku-kas-bud.html',          icon: 'fa-cash-register',       label: 'Buku Kas Umum (BUD)' },
            { href: 'laporan-kas-harian.html',    icon: 'fa-calendar-day',        label: 'Laporan Kas Harian' },
            { href: 'rekonsiliasi-bank.html',     icon: 'fa-money-check-dollar',  label: 'Rekonsiliasi Bank' }
        ] },
        { href: 'register-ppkd.html', icon: 'fa-clipboard-list', label: 'Register SPP/SPM/SP2D' },
        { href: 'lpj-ppkd.html', icon: 'fa-file-signature', label: 'LPJ Bendahara PPKD' },
        { href: 'buku-pembantu.html', icon: 'fa-book-bookmark', label: 'Buku Pembantu Objek' },
        { href: 'master-akun.html',  icon: 'fa-sitemap',        label: 'Master Akun' },
        { href: 'anggaran.html',     icon: 'fa-sack-dollar',    label: 'Anggaran (Pagu)' },
        // Submenu "Laporan Keuangan"
        { key: 'Keuangan', icon: 'fa-chart-line', label: 'Laporan Keuangan', sub: [
            { href: 'neraca.html', icon: 'fa-scale-balanced', label: 'Neraca' },
            { href: 'lra.html',    icon: 'fa-chart-column',   label: 'LRA' }
        ] }
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

    function linkHtml(it, aktif) {
        return '<a href="' + esc(it.href) + '" class="side-link' + (aktif ? ' active' : '') + '">' +
                   '<i class="fas ' + esc(it.icon) + '"></i> ' + esc(it.label) +
               '</a>';
    }

    /** Dropdown: grup yang bisa dibuka/tutup (pola sama dengan "Laporan Keuangan"). */
    function grupHtml(grup, cur) {
        var adaAktif = false;
        var subHtml = grup.sub.map(function (it) {
            var aktif = (it.href !== '#') && (it.href === cur);
            if (aktif) adaAktif = true;
            return linkHtml(it, aktif);
        }).join('');

        return '<div class="side-group' + (adaAktif ? ' open' : '') + '" id="sideGroup' + esc(grup.key) + '">' +
                   '<a href="#" class="side-link" id="sideLink' + esc(grup.key) + '">' +
                       '<i class="fas ' + esc(grup.icon) + '"></i> ' + esc(grup.label) +
                       ' <i class="fas fa-chevron-right side-arrow"></i>' +
                   '</a>' +
                   '<div class="side-sub' + (adaAktif ? ' open' : '') + '" id="sideSub' + esc(grup.key) + '">' + subHtml + '</div>' +
               '</div>';
    }

    function render() {
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        var cur = currentFile();
        var html = '<div class="side-title">Menu Utama</div>';

        ITEMS.forEach(function (it) {
            if (it.sub && it.sub.length) html += grupHtml(it, cur);
            else html += linkHtml(it, (it.href !== '#') && (it.href === cur));
        });

        sidebar.innerHTML = html;

        // Pasang toggle buka/tutup untuk setiap grup dropdown
        ITEMS.forEach(function (it) {
            if (!it.sub || !it.sub.length) return;
            var grup = document.getElementById('sideGroup' + it.key);
            var sub  = document.getElementById('sideSub' + it.key);
            var link = document.getElementById('sideLink' + it.key);
            if (!grup || !sub || !link) return;
            link.addEventListener('click', function (e) {
                e.preventDefault();
                grup.classList.toggle('open');
                sub.classList.toggle('open');
            });
        });
    }

    // Render langsung (sinkron). File ini dimuat SETELAH elemen
    // <aside class="sidebar"> dan SEBELUM js/config.js dipakai
    // mem-decorate link, agar semua link menu ikut dibawa ?token=.
    render();
})();
