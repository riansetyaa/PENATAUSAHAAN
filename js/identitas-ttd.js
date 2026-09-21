/**
 * SIM-TKD - Identitas Penandatangan (Nama & NIP manual)
 * ======================================================
 * Melengkapi js/ttd-nip.js: selain NIP dari Profil, beberapa dokumen
 * (STS, BKU Belanja, BKU Penerimaan, SPD, SP2D, dst.) perlu nama & NIP
 * penandatangan yang diisi MANUAL di halaman.
 *
 * Cara pakai di halaman:
 *   1. Muat script ini SETELAH js/ttd-nip.js:
 *        <script src="../js/ttd-nip.js"></script>
 *        <script src="../js/identitas-ttd.js"></script>
 *   2. Sediakan input (mis. di kartu di atas tombol cetak):
 *        <input type="text" data-ident="nama">
 *        <input type="text" data-ident="nip">
 *   3. Tandai elemen dokumen yang harus diisi:
 *        <div data-ident-target="nama">(______________________)</div>
 *        <div data-ident-target="nip" data-nip-ttd>NIP. ......</div>
 *
 * Perilaku:
 *   - Nilai disimpan di localStorage (kunci simtkd_ident_ttd) sehingga
 *     tidak perlu diisi ulang tiap dokumen.
 *   - Bila dikosongkan, dokumen kembali ke teks dasar (nama pengguna dari
 *     API / NIP Profil bila elemen punya atribut data-nip-ttd).
 *   - NIP manual selalu menang walau NIP Profil dimuat belakangan (async).
 */
(function () {
    'use strict';

    var KEY = 'simtkd_ident_ttd';
    var NAMA_KOSONG = '(______________________)';
    var NIP_KOSONG = '..............................';
    var ATTR_ASLI = 'data-ident-asli';

    var data = { nama: '', nip: '' };
    try {
        var s = JSON.parse(localStorage.getItem(KEY) || 'null');
        if (s) {
            data.nama = String(s.nama || '');
            data.nip = String(s.nip || '');
        }
    } catch (e) { /* localStorage tidak tersedia: biarkan kosong */ }

    function simpan() {
        try { localStorage.setItem(KEY, JSON.stringify(data)); } catch (e) { }
    }

    function target(jenis) {
        return document.querySelectorAll('[data-ident-target="' + jenis + '"]');
    }

    /**
     * Tulis nilai manual ke dokumen; simpan teks dasar agar bisa dipulihkan
     * saat input dikosongkan kembali.
     */
    function terapkan() {
        var i, el, daftar;

        daftar = target('nama');
        for (i = 0; i < daftar.length; i++) {
            el = daftar[i];
            if (data.nama) {
                if (!el.hasAttribute(ATTR_ASLI)) el.setAttribute(ATTR_ASLI, el.textContent);
                el.textContent = data.nama;
            } else if (el.hasAttribute(ATTR_ASLI)) {
                el.textContent = el.getAttribute(ATTR_ASLI);
                el.removeAttribute(ATTR_ASLI);
            }
        }

        daftar = target('nip');
        for (i = 0; i < daftar.length; i++) {
            el = daftar[i];
            if (data.nip) {
                if (!el.hasAttribute(ATTR_ASLI)) el.setAttribute(ATTR_ASLI, el.textContent);
                el.textContent = 'NIP. ' + data.nip;
            } else if (el.hasAttribute(ATTR_ASLI)) {
                el.textContent = el.getAttribute(ATTR_ASLI);
                el.removeAttribute(ATTR_ASLI);
            } else if (!el.hasAttribute('data-nip-ttd')) {
                // Tanpa NIP manual & tanpa NIP profil: pakai titik-titik.
                el.textContent = 'NIP. ' + (el.getAttribute('data-ident-kosong') || NIP_KOSONG);
            }
        }
    }

    function pasangInput() {
        [['nama', 'nama'], ['nip', 'nip']].forEach(function (p) {
            var inp = document.querySelector('[data-ident="' + p[0] + '"]');
            if (!inp) return;
            if (data[p[1]]) inp.value = data[p[1]];
            inp.addEventListener('input', function () {
                data[p[1]] = String(inp.value || '').trim();
                simpan();
                terapkan();
            });
        });
    }

    function mulai() {
        pasangInput();
        terapkan();
        // NIP Profil dimuat asinkron oleh js/ttd-nip.js -> terapkan ulang
        // supaya NIP manual tetap menang.
        if (window.SIMTKD_TTD && window.SIMTKD_TTD.muat) {
            window.SIMTKD_TTD.muat(function () { terapkan(); });
        }
    }

    window.SIMTKD_IDENT = {
        get nama() { return data.nama; },
        get nip() { return data.nip; },
        terapkan: terapkan,
        kosongNama: function () { return NAMA_KOSONG; }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mulai);
    } else {
        mulai();
    }
})();
