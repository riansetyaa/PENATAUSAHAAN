<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Menu AKLAP versi Laravel
|--------------------------------------------------------------------------
|
| STRUKTUR MENU INI ADALAH SALINAN `peta.simtkd.com/js/menu.js` (versi
| legacy/PENATAUSAHAAN): label, ikon Font Awesome, URUTAN, dan grup dropdown
| dibuat sama persis supaya pengguna melihat menu yang identik dengan
| aplikasi lama saat sebuah halaman dialihkan ke Laravel.
|
| Setiap butir punya `route` (halaman Laravel) ATAU `legacy` (halaman
| vanilla yang belum diporting). Pola strangler fig: halaman dipindahkan
| satu per satu tanpa membuat menu pernah mati.
|
| Butir dengan `anak` dirender sebagai dropdown (kelas `side-group`,
| `side-sub`, `side-arrow`) — sama seperti `grupHtml()` pada legacy.
| Kunci `kunci` dipakai sebagai id elemen (`sideGroup<Kunci>`), meniru
| `id="sideGroupBukuKas"` / `id="sideGroupKeuangan"` versi legacy.
|
| Format: ['label', 'ikon', 'route'|'legacy'] atau
|         ['label', 'ikon', 'kunci', 'anak' => [[label, ikon, route|legacy], ...]]
|
*/

return [
    // --- Butir tunggal (urutan sama dengan ITEMS pada menu.js) ---
    ['label' => 'Dashboard',                 'ikon' => 'fa-th-large',            'route'  => 'aklap.beranda'],
    ['label' => 'Jurnal Umum',               'ikon' => 'fa-book',                'legacy' => 'jurnal-umum.html'],
    ['label' => 'Buku Jurnal Khusus',         'ikon' => 'fa-file-invoice-dollar', 'legacy' => 'jurnal-khusus.html'],
    ['label' => 'Jurnal Khusus Konsolidator', 'ikon' => 'fa-layer-group',         'legacy' => 'jurnal-khusus-konsolidator.html'],
    ['label' => 'Buku Besar',                'ikon' => 'fa-table-list',          'legacy' => 'buku-besar.html'],
    ['label' => 'Neraca Saldo',              'ikon' => 'fa-scale-balanced',      'legacy' => 'neraca-saldo.html'],

    // --- Dropdown "Buku Kas" (id legacy: sideGroupBukuKas) ---
    [
        'label' => 'Buku Kas',
        'ikon'  => 'fa-cash-register',
        'kunci' => 'BukuKas',
        'anak'  => [
            ['label' => 'Buku Kas Penerimaan',  'ikon' => 'fa-money-bill-wave',     'legacy' => 'buku-penerimaan-ppkd.html'],
            ['label' => 'Buku Kas Pengeluaran', 'ikon' => 'fa-money-bill-transfer', 'route'  => 'aklap.pengeluaran'],
            ['label' => 'Buku Kas Umum (BUD)',  'ikon' => 'fa-cash-register',       'legacy' => 'buku-kas-bud.html'],
            ['label' => 'Laporan Kas Harian',   'ikon' => 'fa-calendar-day',        'legacy' => 'laporan-kas-harian.html'],
            ['label' => 'Rekonsiliasi Bank',    'ikon' => 'fa-money-check-dollar',  'legacy' => 'rekonsiliasi-bank.html'],
        ],
    ],

    // --- Butir tunggal lanjutan ---
    ['label' => 'Register SPP/SPM/SP2D', 'ikon' => 'fa-clipboard-list', 'legacy' => 'register-ppkd.html'],
    ['label' => 'LPJ Bendahara PPKD',    'ikon' => 'fa-file-signature', 'legacy' => 'lpj-ppkd.html'],
    ['label' => 'Buku Pembantu Objek',   'ikon' => 'fa-book-bookmark',  'legacy' => 'buku-pembantu.html'],
    ['label' => 'Master Akun',           'ikon' => 'fa-sitemap',        'legacy' => 'master-akun.html'],
    ['label' => 'Anggaran (Pagu)',       'ikon' => 'fa-sack-dollar',    'legacy' => 'anggaran.html'],

    // --- Dropdown "Laporan Keuangan" (id legacy: sideGroupKeuangan) ---
    [
        'label' => 'Laporan Keuangan',
        'ikon'  => 'fa-chart-line',
        'kunci' => 'Keuangan',
        'anak'  => [
            ['label' => 'Neraca', 'ikon' => 'fa-scale-balanced', 'legacy' => 'neraca.html'],
            ['label' => 'LRA',    'ikon' => 'fa-chart-column',   'legacy' => 'lra.html'],
        ],
    ],
];
