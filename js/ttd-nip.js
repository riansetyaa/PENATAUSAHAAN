/**
 * SIM-TKD - Helper NIP pada Blok Tanda Tangan
 * ============================================================
 * Mengambil NIP pengguna dari profil (Pengaturan > Profil) SEKALI
 * saat halaman dimuat, lalu:
 *   1. mengisi otomatis semua elemen ber-atribut data-nip-ttd
 *      dengan teks "NIP. <nip>";
 *   2. menyediakan nilai NIP untuk dokumen yang dibangun via JS
 *      (cetak / PDF / kirim ke tanda tangan):
 *         SIMTKD_TTD.nip            -> string NIP (kosong bila belum diisi)
 *         SIMTKD_TTD.teks()         -> "NIP. <nip>" atau placeholder titik-titik
 *         SIMTKD_TTD.muat(cb)       -> cb(nip) dijamin dipanggil setelah data siap
 *         SIMTKD_TTD.isi(root)      -> paksa isi ulang elemen data-nip-ttd
 *         SIMTKD_TTD.onChange(cb)   -> dipanggil tiap NIP selesai dimuat
 *
 * Cara pakai di halaman:
 *   <script src="../js/ttd-nip.js"></script>
 *   ...
 *   <p>NIP. <span data-nip-ttd>&hellip;&hellip;&hellip;</span></p>
 */
(function () {
    'use strict';

    var PLACEHOLDER = '..............................';
    var nip = '';
    var selesai = false;
    var mulai = false;
    var pendengar = [];

    // Cari prefix path dari tag <script> milik helper ini sendiri
    // (halaman di root: "js/ttd-nip.js" -> '', di subfolder: "../js/ttd-nip.js" -> "../")
    var base = '';
    try {
        var tag = document.currentScript;
        if (!tag) {
            var semua = document.getElementsByTagName('script');
            for (var i = semua.length - 1; i >= 0; i--) {
                if (/ttd-nip\.js(\?|$)/.test(semua[i].src || '')) { tag = semua[i]; break; }
            }
        }
        if (tag && tag.getAttribute('src')) {
            base = tag.getAttribute('src').replace(/js\/ttd-nip\.js(\?.*)?$/, '');
        }
    } catch (e) { base = ''; }

    function teks() {
        return 'NIP. ' + (nip || PLACEHOLDER);
    }

    function isi(root) {
        var scope = root || document;
        var list = scope.querySelectorAll ? scope.querySelectorAll('[data-nip-ttd]') : [];
        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            // data-nip-ttd="nilai"  -> hanya angkanya ("19900315 ...")
            // data-nip-ttd          -> lengkap dengan awalan ("NIP. 19900315 ...")
            var mode = el.getAttribute('data-nip-ttd');
            el.textContent = (mode === 'nilai') ? (nip || PLACEHOLDER) : teks();
        }
    }

    function umumkan() {
        isi(document);
        var daftar = pendengar.splice(0);
        for (var i = 0; i < daftar.length; i++) {
            try { daftar[i](nip); } catch (e) { }
        }
    }

    function muat(cb) {
        if (cb) {
            if (selesai) { cb(nip); } else { pendengar.push(cb); }
        }
        if (mulai) { return; }
        mulai = true;

        fetch(base + 'api/profil.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success && d.profil) { nip = String(d.profil.nip || ''); }
            })
            .catch(function () { /* biarkan placeholder */ })
            .then(function () {
                selesai = true;
                umumkan();
            });
    }

    window.SIMTKD_TTD = {
        get nip() { return nip; },
        teks: teks,
        isi: isi,
        muat: muat,
        ubah: function (nilai) { nip = String(nilai || ''); isi(document); }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { muat(); });
    } else {
        muat();
    }
})();
