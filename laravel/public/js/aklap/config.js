/**
 * SIM-TKD (AKLAP) — Konfigurasi API Frontend untuk aplikasi Laravel (Fase 4).
 * =====================================================================
 *
 * Berkas ini PENGGANTI `peta.simtkd.com/js/config.js` versi legacy.
 *
 * Perbedaan mendasar:
 *  - Legacy mengambil token per-user dari `?token=` / localStorage lalu
 *    menempelkannya ke setiap tautan dan panggilan API.
 *  - Laravel memakai SESI (Sanctum stateful): cookie sesi terkirim otomatis
 *    untuk request same-origin, jadi TIDAK ADA token di URL.
 *
 * `SIMTKD_API` tetap disediakan dengan bentuk yang sama supaya menu.js dan
 * sidebar.js (salinan persis dari legacy) serta skrip halaman tidak perlu
 * diubah bentuk pemanggilannya.
 *
 * `window.SIMTKD_LEGACY_BASE` diisi oleh Blade:
 *  - produksi: '' (halaman .html berada di host yang sama) -> tautan identik
 *    seperti web sebelumnya;
 *  - pengembangan: alamat server legacy, supaya tautan halaman yang belum
 *    diporting tetap bisa dibuka.
 */
(function () {
    'use strict';

    var LEGACY = (typeof window.SIMTKD_LEGACY_BASE === 'string') ? window.SIMTKD_LEGACY_BASE : '';

    // Halaman yang SUDAH diporting ke Laravel: tautan diarahkan ke route baru.
    var RUTE_LARAVEL = {
        'index.html': '/aklap',
        'buku-pengeluaran-ppkd.html': '/aklap/buku-pengeluaran'
    };

    window.SIMTKD_API = {
        /** Basis endpoint AKLAP Laravel. */
        endpoint: '/api/v1/aklap',

        /** Legacy memakai token; Laravel memakai sesi -> selalu string kosong. */
        getToken: function () { return ''; },

        /** Tidak ada lagi penempelan token pada tautan. */
        init: function () { return this; },
        decorate: function () { return this; },

        /** URL absolut ke halaman legacy (untuk tautan yang belum diporting). */
        legacyUrl: function (berkas) {
            return LEGACY + berkas;
        },

        /** Peta halaman -> route Laravel (null bila belum diporting). */
        ruteLaravel: function (berkas) {
            return RUTE_LARAVEL[berkas] || null;
        }
    };

    // ------------------------------------------------------------------
    // Sesuaikan tautan sidebar setelah menu.js merender.
    // Tautan yang sudah punya route Laravel diarahkan ke route tersebut,
    // sisanya memakai SIMTKD_LEGACY_BASE (kosong di produksi -> persis
    // seperti web sebelumnya).
    // ------------------------------------------------------------------
    function rapikanTautan() {
        // Tombol "Kembali ke Penatausahaan" — sama seperti legacy config.js:
        // di produksi menuju https://simtkd.com/dashboard.html (document root
        // subdomain terpisah), saat diuji lokal diarahkan ke dashboard app utama.
        var host = window.location.hostname;

        if (host === '127.0.0.1' || host === 'localhost') {
            var kembali = document.querySelectorAll('a[data-back-main]');

            for (var k = 0; k < kembali.length; k++) {
                kembali[k].setAttribute('href', window.SIMTKD_DASHBOARD_LOKAL || '/');
            }
        }

        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        var tautan = sidebar.querySelectorAll('a[href]');

        for (var i = 0; i < tautan.length; i++) {
            var a = tautan[i];
            var href = a.getAttribute('href');

            if (!href || href === '#' || href.indexOf('://') !== -1) continue;
            if (href.indexOf('.html') === -1) continue;

            var berkas = href.split('/').pop();
            var rute = SIMTKD_API.ruteLaravel(berkas);

            if (rute) {
                a.setAttribute('href', rute);
            } else if (LEGACY !== '') {
                a.setAttribute('href', SIMTKD_API.legacyUrl(berkas));
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', rapikanTautan);
    } else {
        rapikanTautan();
    }
})();
