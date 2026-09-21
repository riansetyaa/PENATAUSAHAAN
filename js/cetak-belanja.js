/**
 * SIM-TKD - Helper Cetak Dokumen Belanja
 * ============================================
 * Membuka window baru berisi dokumen resmi (format print A4) sesuai
 * contoh "Modul Belanja SKPKD", lengkap dengan PANEL PENGATURAN LAMAN
 * CETAK (seperti modul BKU): ukuran font, jarak tulisan, jenis font,
 * ketebalan & warna garis, warna tulisan, dan orientasi halaman.
 * Setelan tersimpan (localStorage) dan otomatis diterapkan.
 *
 * Dipakai oleh: spd.html, spp.html, spm.html, sp2d.html, lpj.html,
 * pengajuan-tu.html, npd.html, rekanan.html, rekening.html.
 */
(function (global) {
    'use strict';

    var SETTINGS_KEY = 'simtkd_cetak_settings';
    var TN = "'Times New Roman', Times, serif";   // font default dokumen resmi

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function rupiah(v) {
        return 'Rp ' + Number(v || 0).toLocaleString('id-ID');
    }

    function tgl(s) {
        if (!s) return '-';
        var p = String(s).split('-');
        var b = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return (parseInt(p[2], 10) || p[2]) + ' ' + b[(parseInt(p[1], 10) - 1) || 0] + ' ' + (p[0] || '');
    }

    function tglPanjang(s) {
        if (!s) return '-';
        return tgl(s);
    }

    // Nama instansi/SKPD aktif (dari sidebar yang diisi dashboard.js)
    function instansi() {
        var el = document.getElementById('sidebarUserRole');
        if (el && el.textContent && el.textContent.trim() !== '') return el.textContent.trim();
        el = document.getElementById('topbarUserName');
        return el ? el.textContent.trim() : '';
    }

    // Nama akun/pengguna aktif (dari sidebar yang diisi dashboard.js)
    function namaAkun() {
        var el = document.getElementById('sidebarUserName');
        if (el && el.textContent && el.textContent.trim() !== '') return el.textContent.trim();
        el = document.getElementById('topbarUserName');
        return el ? el.textContent.trim() : '';
    }

    var FONT_OPTIONS = [
        ['Times New Roman', TN],
        ['Inter, Sans-Serif', "'Inter', system-ui, sans-serif"],
        ['Arial, Helvetica, sans-serif', "Arial, Helvetica, sans-serif"],
        ['Courier New, monospace', "'Courier New', monospace"]
    ];

    var CSS = [
        '* { box-sizing: border-box; }',
        'html, body { margin: 0; padding: 0; }',
        'body { background: #eef1f6; font-family: -apple-system, "Segoe UI", Roboto, sans-serif; color: #111; }',
        '',
        '/* ===== Panel Pengaturan Laman Cetak ===== */',
        '.cetak-settings { position: sticky; top: 0; z-index: 50; background: #ffffff; border-bottom: 1px solid #d9e0ea; box-shadow: 0 2px 10px rgba(15,23,42,.08); padding: 14px 22px; }',
        '.cs-head { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #1a3a6b; margin-bottom: 12px; }',
        '.cs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px 14px; }',
        '.cs-field label { display: block; font-size: 11px; font-weight: 600; color: #5a6c7d; margin-bottom: 5px; }',
        '.cs-field input[type="number"], .cs-field select { width: 100%; padding: 8px 10px; border: 1px solid #d0d5dd; border-radius: 7px; font-size: 13px; font-family: inherit; color: #111; background: #fff; }',
        '.cs-field input[type="text"] { width: 100%; padding: 8px 10px; border: 1px solid #d0d5dd; border-radius: 7px; font-size: 13px; font-family: inherit; color: #111; background: #fff; }',
        '.cs-field input:focus, .cs-field select:focus { outline: none; border-color: #2c5aa0; box-shadow: 0 0 0 3px rgba(44,90,160,.12); }',
        '.cs-ident { margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px dashed #d9e0ea; }',
        '.cs-ident-title { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #5a6c7d; margin-bottom: 10px; }',
        '.cs-color { display: flex; align-items: center; gap: 8px; border: 1px solid #d0d5dd; border-radius: 7px; padding: 5px 8px; background: #fff; }',
        '.cs-color input[type="color"] { width: 32px; height: 24px; padding: 0; border: 1px solid #d0d5dd; border-radius: 5px; background: none; cursor: pointer; }',
        '.cs-color span { font-family: "SF Mono", Consolas, monospace; font-size: 11px; color: #5a6c7d; }',
        '.cs-toggle { display: flex; border: 1px solid #d0d5dd; border-radius: 7px; overflow: hidden; background: #f8fafc; }',
        '.cs-toggle button { flex: 1; padding: 8px 4px; border: none; background: transparent; font-size: 12px; font-weight: 600; color: #5a6c7d; cursor: pointer; font-family: inherit; }',
        '.cs-toggle button.active { background: #1a3a6b; color: #fff; }',
        '.cs-actions { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; }',
        '.cs-actions button { padding: 10px 22px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }',
        '.cs-actions .cs-print { background: #1a3a6b; color: #fff; }',
        '.cs-actions .cs-print:hover { background: #2c5aa0; }',
        '.cs-actions .cs-close { background: #eef1f6; color: #5a6c7d; border: 1px solid #d0d5dd; }',
        '.cs-actions .cs-ttd { background: #0f766e; color: #fff; }',
        '.cs-actions .cs-ttd:hover { background: #0d9488; }',
        '.cs-actions .cs-ttd:disabled { opacity: .75; cursor: default; }',
        '.cs-ttd-status { width: 100%; min-height: 15px; font-size: 12px; font-weight: 600; color: #0f766e; }',
        '.cs-hint { margin-top: 8px; font-size: 11px; color: #9098a5; }',
        '',
        '/* ===== Dokumen ===== */',
        '#docArea { width: 210mm; min-height: 297mm; margin: 22px auto; background: #ffffff; padding: 18mm 16mm; box-shadow: 0 8px 30px rgba(15,23,42,.10); color: var(--tc, #000); font-family: var(--font, "Times New Roman", Times, serif); font-size: var(--fs, 13px); line-height: var(--lh, 1.6); }',
        '#docArea .kop { text-align: center; margin-bottom: 14px; }',
        '#docArea .kop .pem { font-size: calc(var(--fs,13px) + 5px); font-weight: 700; letter-spacing: 1px; }',
        '#docArea .kop .din { font-size: calc(var(--fs,13px) + 3px); font-weight: 700; margin-top: 2px; text-transform: uppercase; }',
        '#docArea .kop .alamat { font-size: calc(var(--fs,13px) - 2px); margin-top: 3px; }',
        '#docArea .kop .garis { border-bottom: 2.5px solid var(--lc, #000); margin-top: 8px; }',
        '#docArea .judul { text-align: center; font-size: calc(var(--fs,13px) + 3px); font-weight: 700; margin: 18px 0 2px; }',
        '#docArea .subjudul { text-align: center; font-size: calc(var(--fs,13px) + 1px); font-weight: 700; margin-bottom: 2px; }',
        '#docArea .nomor { text-align: center; font-size: var(--fs,13px); margin-bottom: 16px; }',
        '#docArea table.f { width: 100%; border-collapse: collapse; font-size: var(--fs,13px); margin-bottom: 14px; }',
        '#docArea table.f td { border: var(--lw, 1px) solid var(--lc, #000); padding: 7px 9px; vertical-align: top; color: var(--tc, #000); }',
        '#docArea table.f td.lbl { width: 34%; font-weight: 700; background: #f7f7f7; }',
        '#docArea table.d { width: 100%; border-collapse: collapse; font-size: var(--fs,13px); margin: 8px 0 16px; }',
        '#docArea table.d th, #docArea table.d td { border: var(--lw, 1px) solid var(--lc, #000); padding: 6px 8px; vertical-align: top; color: var(--tc, #000); }',
        '#docArea table.d th { background: #e8eef7; text-align: center; }',
        '#docArea table.d td.num, #docArea table.d th.num { text-align: right; }',
        '#docArea table.rd { width: 100%; border-collapse: collapse; font-size: var(--fs,13px); margin: 8px 0 16px; }',
        '#docArea table.rd td { border: var(--lw,1px) solid var(--lc,#000); padding: 5px 8px; vertical-align: top; color: var(--tc,#000); }',
        '#docArea table.rd tr.dot td { border-bottom-style: dotted; }',
        '#docArea table.rd td.ctr { text-align: center; }',
        '#docArea table.rd td.num { text-align: right; }',
        '#docArea table.rd td.b { font-weight: 700; }',
        '#docArea .catatan { font-size: calc(var(--fs,13px) - 2px); font-style: italic; margin: 4px 0 12px; }',
        '#docArea .total { font-weight: 700; text-align: right; }',
        '#docArea .ttd { display: flex; justify-content: space-between; margin-top: 60px; font-size: var(--fs,13px); }',
        '#docArea .ttd .col { text-align: center; width: 42%; }',
        '#docArea .ttd .nama { font-weight: 700; margin-top: 56px; }',
        '#docArea .ttd .nip { font-size: calc(var(--fs,13px) - 1px); margin-top: 2px; }',
        '#docArea .ttd .kosong { height: 56px; }',
        '#docArea .ttd-slot { min-height: 0; }',
        '#docArea .ttd-slot img { display: block; margin: 0 auto; max-width: 220px; max-height: 64px; }',
        '',
        '/* ===== Format surat resmi (SPP / SPM / SP2D) ===== */',
        '#docArea .kop .garis2 { border-bottom: 3px double var(--lc, #000); margin: 7px 0 16px; }',
        '#docArea .blok { margin-top: 16px; }',
        '#docArea .blok-title { text-align: center; font-weight: 700; text-decoration: underline; font-size: var(--fs,13px); margin: 20px 0 8px; }',
        '#docArea .surat { margin: 4px 0; }',
        '#docArea .surat.tgl { text-align: right; }',
        '#docArea .kepada { margin: 8px 0 12px; }',
        '#docArea .item { margin: 3px 0; }',
        '#docArea .item .lbl { display: inline-block; min-width: 215px; }',
        '#docArea .item.terbilang { margin-left: 150pt; }',
        '#docArea .page-break { page-break-after: always; }',
        '#docArea table.d tr.total-row td { border-top: 2px solid var(--lc,#000) !important; font-weight: 700; }',
        '#docArea .rincian-title { text-align: center; font-weight: 700; text-decoration: underline; margin: 20px 0 8px; }',
        '#docArea .rincian-ket { margin: 2px 0 6px; }',
        '#docArea .ttd-single { width: 46%; margin: 30px 0 0 auto; text-align: center; }',
        '#docArea .ttd-single .ttd-jabatan { font-weight: 700; text-transform: uppercase; }',
        '#docArea .ttd-single .ttd-space { height: 56px; }',
        '#docArea .ttd-single .ttd-nama { font-weight: 700; }',
        '#docArea .ttd-single .ttd-nip { font-size: calc(var(--fs,13px) - 1px); }',
        '#docArea .spm-ket { margin: 10px 0; font-style: italic; text-align: center; }',
        '#docArea .spm-kepada { margin: 10px 0 4px; }',
        '#docArea .spm-info { margin: 8px 0 6px; }',
        '#docArea .spm-info .item { margin: 4px 0; }',
        '#docArea .spm-info .item .lbl { min-width: 175px; }',
        '#docArea .spm-box { border: var(--lw,1px) solid var(--lc,#000); background: #fdf6d8; padding: 10px 16px; margin: 12px 0; }',
        '#docArea .spm-box .item { margin: 2px 0; }',
        '#docArea .spm-head { text-align: right; margin-bottom: 6px; font-size: var(--fs,13px); }',
        '#docArea table.spm-tbl { width: 100%; border-collapse: collapse; font-size: 12px; margin: 8px 0 14px; color: var(--tc,#000); }',
        '#docArea table.spm-tbl td, #docArea table.spm-tbl th { border: 1px solid var(--lc,#000); padding: 4px 6px; vertical-align: top; }',
        '#docArea table.spm-tbl .lbl { font-weight: 700; }',
        '#docArea table.spm-tbl .num { text-align: right; }',
        '#docArea table.spm-tbl .spm-yellow { background: #fdf6d8; }',
        '',
        '@media print {',
        '  body { background: #fff; }',
        '  .cetak-settings, .no-print { display: none !important; }',
        '  #docArea { margin: 0; box-shadow: none; padding: 0; width: auto; min-height: auto; }',
        '}'
    ].join('\n');

    // Panel JS ditulis ke window cetak. Default font memakai variabel TN agar
    // tidak ada masalah escaping kutip di dalam string.
    var PANEL_JS = [
        'function csGet(id, fb) { var el = document.getElementById(id); return (el && el.value !== "") ? el.value : fb; }',
        'function csApply() {',
        '  var doc = document.getElementById("docArea");',
        '  if (!doc) return;',
        '  doc.style.setProperty("--fs", csGet("csFontSize", "13") + "px");',
        '  doc.style.setProperty("--lh", csGet("csLineHeight", "1.6"));',
        '  doc.style.setProperty("--font", csGet("csFont", "' + TN + '"));',
        '  doc.style.setProperty("--lw", csGet("csLineWidth", "1") + "px");',
        '  doc.style.setProperty("--lc", csGet("csLineColor", "#000000"));',
        '  doc.style.setProperty("--tc", csGet("csTextColor", "#000000"));',
        '  var ob = document.querySelector("#csOrient button.active");',
        '  var o = (ob && ob.getAttribute) ? ob.getAttribute("data-o") : "portrait";',
        '  document.getElementById("pageStyle").textContent = o === "landscape" ? "@page { size: A4 landscape; margin: 12mm; }" : "@page { size: A4 portrait; margin: 14mm; }";',
        '  var save = { fs: csGet("csFontSize","13"), lh: csGet("csLineHeight","1.6"), font: csGet("csFont", "' + TN + '"), lw: csGet("csLineWidth","1"), lc: csGet("csLineColor","#000000"), tc: csGet("csTextColor","#000000"), orient: o };',
        '  try { localStorage.setItem("' + SETTINGS_KEY + '", JSON.stringify(save)); } catch (e) {}',
        '}',
        'function csInit() {',
        '  var s = null;',
        '  try { s = JSON.parse(localStorage.getItem("' + SETTINGS_KEY + '")); } catch (e) {}',
        '  if (s) {',
        '    if (s.fs) document.getElementById("csFontSize").value = s.fs;',
        '    if (s.lh) document.getElementById("csLineHeight").value = s.lh;',
        '    if (s.font) document.getElementById("csFont").value = s.font;',
        '    if (s.lw) document.getElementById("csLineWidth").value = s.lw;',
        '    if (s.lc) { document.getElementById("csLineColor").value = s.lc; document.getElementById("csLineColorVal").textContent = s.lc; }',
        '    if (s.tc) { document.getElementById("csTextColor").value = s.tc; document.getElementById("csTextColorVal").textContent = s.tc; }',
        '    if (s.orient) { document.querySelectorAll("#csOrient button").forEach(function(b){ b.classList.toggle("active", b.getAttribute("data-o")===s.orient); }); }',
        '  }',
        '  ["csFontSize","csLineHeight","csFont","csLineWidth","csLineColor","csTextColor"].forEach(function(id){',
        '    var el = document.getElementById(id); if (el) el.addEventListener("input", csApply);',
        '  });',
        '  ["csLineColor","csTextColor"].forEach(function(id){',
        '    var el = document.getElementById(id); var val = document.getElementById(id+"Val");',
        '    if (el && val) el.addEventListener("input", function(){ val.textContent = el.value; });',
        '  });',
        '  document.querySelectorAll("#csOrient button").forEach(function(b){',
        '    b.addEventListener("click", function(){ document.querySelectorAll("#csOrient button").forEach(function(x){ x.classList.remove("active"); }); b.classList.add("active"); csApply(); });',
        '  });',
        '  csApply();',
        '  csIdentInit();',
        '}',
        '/* Identitas penandatangan manual (nama & NIP) - laman ini hidup di',
        '   dokumen terpisah (window.open), jadi logikanya mandiri; halaman',
        '   biasa memakai js/identitas-ttd.js dengan kunci localStorage sama. */',
        'var csIdent = (function () { try { return JSON.parse(localStorage.getItem("simtkd_ident_ttd")) || {}; } catch (e) { return {}; } })();',
        'function csIdentTulis(el, nilai, awalan) {',
        '  if (!el) return;',
        '  if (nilai) {',
        '    if (!el.getAttribute("data-ident-asli")) el.setAttribute("data-ident-asli", el.textContent);',
        '    el.textContent = awalan + nilai;',
        '  } else if (el.getAttribute("data-ident-asli")) {',
        '    el.textContent = el.getAttribute("data-ident-asli");',
        '    el.removeAttribute("data-ident-asli");',
        '  } else if (awalan && !el.hasAttribute("data-nip-ttd")) {',
        '    el.textContent = awalan + (el.getAttribute("data-ident-kosong") || "..............................");',
        '  }',
        '}',
        'function csIdentTerapkan() {',
        '  document.querySelectorAll("[data-ident-target=\\"nama\\"]").forEach(function (el) { csIdentTulis(el, csIdent.nama, ""); });',
        '  document.querySelectorAll("[data-ident-target=\\"nip\\"]").forEach(function (el) { csIdentTulis(el, csIdent.nip, "NIP. "); });',
        '}',
        'function csIdentInit() {',
        '  var inpNama = document.getElementById("csIdentNama");',
        '  var inpNip = document.getElementById("csIdentNip");',
        '  if (!inpNama && !inpNip) return;',
        '  if (inpNama && csIdent.nama) inpNama.value = csIdent.nama;',
        '  if (inpNip && csIdent.nip) inpNip.value = csIdent.nip;',
        '  [["csIdentNama", "nama"], ["csIdentNip", "nip"]].forEach(function (p) {',
        '    var el = document.getElementById(p[0]); if (!el) return;',
        '    el.addEventListener("input", function () {',
        '      csIdent[p[1]] = String(el.value || "").trim();',
        '      try { localStorage.setItem("simtkd_ident_ttd", JSON.stringify(csIdent)); } catch (e) {}',
        '      csIdentTerapkan();',
        '    });',
        '  });',
        '  csIdentTerapkan();',
        '}',
        'function csKirimTtd() {',
        '  var btn = document.getElementById("csKirimTtd");',
        '  var out = document.getElementById("csTtdStatus");',
        '  if (!btn || !window.CS_TTD_META) return;',
        '  btn.disabled = true; btn.innerHTML = "&#8987; Mengirim...";',
        '  var body = new URLSearchParams();',
        '  body.append("action", "kirim");',
        '  body.append("jenis", window.CS_TTD_META.jenis || "");',
        '  body.append("ref_id", window.CS_TTD_META.ref_id || "");',
        '  body.append("nomor", window.CS_TTD_META.nomor || "");',
        '  body.append("judul", window.CS_TTD_META.judul || "");',
        '  body.append("tanggal", window.CS_TTD_META.tanggal || "");',
        '  var ob = document.querySelector("#csOrient button.active");',
        '  var orientasi = (ob && ob.getAttribute) ? ob.getAttribute("data-o") : "portrait";',
        '  var pageStyle = (orientasi === "landscape") ? "@page { size: A4 landscape; margin: 12mm; }" : "@page { size: A4 portrait; margin: 14mm; }";',
        '  /* Pakai DOM dokumen saat ini (bukan salinan awal) supaya nama/NIP manual',
        '     pada panel identitas & setelan cetak ikut terkirim. */',
        '  var area = document.getElementById("docArea");',
        '  var css = "";',
        '  document.querySelectorAll("style").forEach(function (st) { css += st.textContent + "\\n"; });',
        '  var isi = area ? (css + area.outerHTML) : (window.CS_TTD_HTML || "");',
        '  body.append("konten_html", \'<style id="pageStyle">\' + pageStyle + \'</style>\' + isi);',
        '  fetch(window.CS_TTD_META.url, { method: "POST", body: body })',
        '    .then(function (r) { return r.json(); })',
        '    .then(function (d) {',
        '      if (d && d.success) {',
        '        btn.innerHTML = "&#10003; Terkirim ke antrean TTD";',
        '        if (out) out.textContent = d.message || "Dokumen terkirim."; }',
        '      else {',
        '        btn.disabled = false;',
        '        btn.innerHTML = "&#9998; Kirim ke Tanda Tangan";',
        '        if (out) out.textContent = (d && d.message) ? d.message : "Gagal mengirim dokumen."; }',
        '    })',
        '    .catch(function () {',
        '      btn.disabled = false;',
        '      btn.innerHTML = "&#9998; Kirim ke Tanda Tangan";',
        '      if (out) out.textContent = "Gagal mengirim (jaringan).";',
        '    });',
        '}',
        'if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", csInit); } else { csInit(); }'
    ].join('\n');

    function fieldRow(label, value) {
        return '<tr><td class="lbl">' + esc(label) + '</td><td>' + esc(value == null ? '' : value) + '</td></tr>';
    }

    function detailTable(rows, cols, opts) {
        opts = opts || {};
        var head = '<tr>' + cols.map(function (c) { return '<th' + (c.num ? ' class="num"' : '') + '>' + esc(c.t) + '</th>'; }).join('') + '</tr>';
        var body = rows.map(function (r, i) {
            return '<tr>' + cols.map(function (c, ci) {
                var v = (ci === 0) ? (i + 1) : (c.key ? r[c.key] : '');
                return '<td' + (c.num ? ' class="num"' : '') + '>' + (c.fmt ? c.fmt(v) : esc(v == null ? '' : v)) + '</td>';
            }).join('') + '</tr>';
        }).join('');
        if (opts.total !== undefined && opts.total !== '') {
            body += '<tr class="total-row"><td colspan="' + (cols.length - 1) + '" style="text-align:right;font-weight:700;">Total</td><td class="num">' + opts.total + '</td></tr>';
        }
        return '<table class="d"><thead>' + head + '</thead><tbody>' + body + '</tbody></table>';
    }

    function ttd(left, right) {
        return '<div class="ttd">' +
            '<div class="col">' +
                (left && left.jabatan ? '<div>' + esc(left.jabatan) + '</div>' : '<div>&nbsp;</div>') +
                '<div class="kosong"></div>' +
                '<div class="ttd-slot"></div>' +
                '<div class="nama" data-ident-target="nama">' + esc((left && left.nama) || '(.........................)') + '</div>' +
                '<div class="nip" data-ident-target="nip" data-ident-kosong="' + esc(nipTtd(left)) + '">NIP. ' + esc(nipTtd(left)) + '</div>' +
            '</div>' +
            '<div class="col">' +
                (right && right.jabatan ? '<div>' + esc(right.jabatan) + '</div>' : '<div>&nbsp;</div>') +
                '<div class="kosong"></div>' +
                '<div class="ttd-slot"></div>' +
                '<div class="nama">' + esc((right && right.nama) || '(.........................)') + '</div>' +
                '<div class="nip">NIP. ' + esc(nipTtd(right)) + '</div>' +
            '</div>' +
        '</div>';
    }

    // ================= Format Surat Resmi (SPP / SPM / SP2D) =================

    // Angka -> kata (Bahasa Indonesia)
    function terbilang(n) {
        n = Math.floor(Math.abs(Number(n) || 0));
        var angka = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];
        if (n < 12) return angka[n];
        if (n < 20) return terbilang(n - 10) + ' Belas';
        if (n < 100) return terbilang(Math.floor(n / 10)) + ' Puluh ' + terbilang(n % 10);
        if (n < 200) return 'Seratus ' + terbilang(n - 100);
        if (n < 1000) return terbilang(Math.floor(n / 100)) + ' Ratus ' + terbilang(n % 100);
        if (n < 2000) return 'Seribu ' + terbilang(n - 1000);
        if (n < 1000000) return terbilang(Math.floor(n / 1000)) + ' Ribu ' + terbilang(n % 1000);
        if (n < 1000000000) return terbilang(Math.floor(n / 1000000)) + ' Juta ' + terbilang(n % 1000000);
        return terbilang(Math.floor(n / 1000000000)) + ' Miliar ' + terbilang(n % 1000000000);
    }
    function terbilangRupiah(n) {
        return '#' + terbilang(n) + ' Rupiah#';
    }
    function blokTitle(t) { return '<div class="blok-title">' + esc(t) + '</div>'; }
    function paragraf(t) { return '<div class="surat">' + esc(t) + '</div>'; }
    function kepada(lines) { return '<div class="kepada">' + (lines || []).map(paragraf).join('') + '</div>'; }
    function itemLine(lbl, val) { return '<div class="item"><span class="lbl">' + esc(lbl) + '</span> : ' + esc(val == null ? '' : val) + '</div>'; }

    /**
     * NIP pada blok tanda tangan.
     * Dipakai objek penandatangan bila menyertakan `nip`; bila tidak,
     * otomatis memakai NIP dari Profil (Pengaturan > Profil) via js/ttd-nip.js.
     */
    function nipTtd(objek) {
        var nip = (objek && objek.nip) || (window.SIMTKD_TTD && window.SIMTKD_TTD.nip) || '';
        return String(nip || '').trim() || '..............................';
    }

    function ttdTunggal(t) {
        return '<div class="ttd-single">' +
            '<div class="ttd-jabatan">' + esc((t && t.jabatan) || 'Bendahara Pengeluaran') + '</div>' +
            '<div class="ttd-space"></div>' +
            '<div class="ttd-slot"></div>' +
            '<div class="ttd-nama">' + esc((t && t.nama) || '(................................)') + '</div>' +
            '<div class="ttd-nip">NIP. ' + esc(nipTtd(t)) + '</div>' +
        '</div>';
    }
    // SURAT PENGANTAR (bagian atas SPP)
    function suratPengantar(d) {
        d = d || {};
        var h = '<div class="blok">' + blokTitle('SURAT PENGANTAR');
        h += kepada(d.kepada || ['Pengguna Anggaran / Kuasa Pengguna Anggaran', 'SKPKD - BPKD', 'Di Tempat']);
        h += paragraf(d.pembuka || 'Dengan memperhatikan Peraturan Bupati Nomor ...... Tahun ...... tentang Penjabaran APBD, bersama ini kami mengajukan permintaan pembayaran sebagai berikut :');
        (d.items || []).forEach(function (it) {
            h += itemLine(it[0], it[1]);
            if (it[2]) h += '<div class="item terbilang">(terbilang : ' + esc(it[2]) + ')</div>';
        });
        h += '<div class="surat tgl">' + esc((d.kota || 'Bandung') + ', ' + (d.tanggal || '.......................')) + '</div>';
        h += ttdTunggal(d.ttd || { jabatan: 'Bendahara Pengeluaran' });
        h += '</div>';
        return h;
    }
    // Blok RINGKASAN / paragraf resmi + TTD
    function ringkasanBlok(judul, lines, ttdOpt) {
        var h = '<div class="blok">' + blokTitle(judul);
        (lines || []).forEach(function (ln) {
            if (ln && ln.tgl) { h += '<div class="surat tgl">' + esc(ln.tgl) + '</div>'; return; }
            h += paragraf(ln);
        });
        h += ttdTunggal(ttdOpt || { jabatan: 'Bendahara Pengeluaran' });
        h += '</div>';
        return h;
    }
    // Tabel Rincian Rencana Penggunaan (nomor, kode, uraian, jumlah)
    function rincianTable(rows, cols, opts) {
        return detailTable(rows || [], cols || [], opts);
    }

    // ===== Tabel Ringkasan resmi SPP (format Modul Belanja SKPKD) =====
    // Satu tabel bergaris berisi: RINGKASAN DPA-/DPPA-/DPAL-SKPD (baris I),
    // RINGKASAN SPD (kolom No Urut / Nomor SPD / Tanggal SPD / Jumlah Dana,
    // baris II dan I - II) serta Ringkasan Belanja (baris III dan II - III).
    // Baris isian mengikuti formulir: batas bawah bergaris putus-putus (dotted).
    // o = {
    //   jumlahDpa     : number|null -> nilai I  (jumlah dana DPA-/DPPA-/DPAL-SKPD)
    //   spdRows       : [{nomor, tanggal, jumlah}] -> isi tabel SPD; minimal
    //                   2 baris bernomor seperti formulir (tanpa data = kosong)
    //   jumlahSpd     : number|null -> nilai II (jumlah kolom Jumlah Dana)
    //   sisaBelumSpd  : number|null -> nilai (I - II); null/undefined = dihitung otomatis
    //   belanja       : { upgu, tu, lsgaji, lsbj } -> nilai per jenis belanja
    //   jumlahBelanja : number|null -> nilai III (jumlah ringkasan belanja)
    //   sisaBelanja   : number|null -> nilai (II - III)
    //   fmt           : function(v)->string, format angka tanpa awalan "Rp"
    // }
    function ringkasanDpaTable(o) {
        o = o || {};
        var fmt = o.fmt || function (v) { return (v == null || v === '') ? '' : Number(v).toLocaleString('id-ID'); };
        function val(v) { return (v == null || v === '') ? '' : esc(fmt(v)); }
        function sec(t, bold) {
            return '<tr><td colspan="4" class="ctr' + (bold ? ' b' : '') + '">' + esc(t) + '</td></tr>';
        }
        // Baris label (colspan 3) + sel angka berawalan I / II / III / (I - II) / (II - III) Rp
        function baris(label, prefix, v, dot) {
            var s = val(v);
            return '<tr' + (dot ? ' class="dot"' : '') + '><td colspan="3">' + esc(label) + '</td>' +
                '<td>' + prefix + (s !== '' ? '&nbsp;' + s : '') + '</td></tr>';
        }
        var h = '<table class="rd">' +
            '<colgroup><col style="width:8.5%"><col style="width:27%"><col style="width:29.5%"><col style="width:35%"></colgroup>' +
            '<tbody>';
        // -- Ringkasan DPA-/DPPA-/DPAL-SKPD
        h += sec('RINGKASAN DPA-/DPPA-/DPAL-SKPD', true);
        h += baris('Jumlah dana DPA-/DPPA-/DPAL-SKPD', 'I &nbsp;Rp ', o.jumlahDpa, false);
        // -- Ringkasan SPD
        h += sec('RINGKASAN SPD', true);
        h += '<tr>' +
            '<td class="ctr">No Urut</td>' +
            '<td class="ctr">Nomor SPD</td>' +
            '<td class="ctr">Tanggal SPD</td>' +
            '<td class="ctr">Jumlah Dana</td>' +
            '</tr>';
        var rows = o.spdRows || [];
        var n = Math.max(2, rows.length);
        for (var i = 0; i < n; i++) {
            var d = rows[i] || {};
            h += '<tr' + (i < n - 1 ? ' class="dot"' : '') + '>' +
                '<td class="ctr">' + (i + 1) + '</td>' +
                '<td>' + esc(d.nomor || '') + '</td>' +
                '<td>' + esc(d.tanggal || '') + '</td>' +
                '<td class="num">' + val(d.jumlah) + '</td>' +
                '</tr>';
        }
        var sisa1 = (o.sisaBelumSpd !== undefined && o.sisaBelumSpd !== null) ? o.sisaBelumSpd
            : ((o.jumlahDpa != null && o.jumlahSpd != null) ? Number(o.jumlahDpa) - Number(o.jumlahSpd) : null);
        h += baris('Jumlah', 'II. Rp', o.jumlahSpd, false);
        h += baris('Sisa dana yang belum di SPD-kan', '(I &ndash; II) Rp ', sisa1, false);
        // -- Ringkasan Belanja
        var bl = o.belanja || {};
        h += sec('Ringkasan Belanja', false);
        h += baris('Belanja UP/GU', '', bl.upgu, true);
        h += baris('Belanja TU', '', bl.tu, true);
        h += baris('Belanja LS Pembayaran Gaji dan Tunjangan', '', bl.lsgaji, true);
        h += baris('Belanja Pengadaan Barang dan Jasa', '', bl.lsbj, false);
        h += baris('Jumlah', 'III. Rp ', o.jumlahBelanja, false);
        h += baris('Sisa SPD yang telah diterbitkan, belum dibelanjakan', '(II - III) &nbsp;Rp', o.sisaBelanja, false);
        h += '</tbody></table>';
        return h;
    }

    function bukaCetak(o) {
        var w = window.open('', '_blank', 'width=980,height=780');
        if (!w) { alert('Izinkan pop-up agar dokumen dapat dicetak.'); return; }

        var fieldsHtml = (o.fields || []).map(function (f) {
            if (Array.isArray(f)) return fieldRow(f[0], f[1]);
            return fieldRow(f.label, f.value);
        }).join('');
        var detailHtml = o.detail ? detailTable(o.detail.rows || [], o.detail.cols || []) : '';
        var totalHtml = o.total ? '<div class="total">' + esc(o.total) + '</div>' : '';
        var ttdHtml = o.ttd ? ttd(o.ttd.left, o.ttd.right) : '';
        var catatanHtml = o.catatan ? '<div class="catatan">' + esc(o.catatan) + '</div>' : '';
        var bodyDefault = (fieldsHtml ? '<table class="f">' + fieldsHtml + '</table>' : '') + detailHtml + totalHtml + catatanHtml + ttdHtml;

        var fontOpts = FONT_OPTIONS.map(function (f) {
            return '<option value="' + esc(f[1]) + '">' + esc(f[0]) + '</option>';
        }).join('');

        var docHtml =
            '<div class="kop">' +
                '<div class="pem">' + esc(o.pem || 'PEMERINTAH KOTA BANDUNG') + '</div>' +
                '<div class="din">' + esc(o.kop || instansi()) + '</div>' +
                (o.alamat ? '<div class="alamat">' + esc(o.alamat) + '</div>' : '') +
                (o.garis2 ? '<div class="garis2"></div>' : '<div class="garis"></div>') +
            '</div>' +
            '<div class="judul">' + esc(o.judul || '') + '</div>' +
            (o.subjudul ? '<div class="subjudul">' + esc(o.subjudul) + '</div>' : '') +
            (o.nomor ? '<div class="nomor">' + esc(o.nomor) + '</div>' : '') +
            (o.bodyHtml ? o.bodyHtml : bodyDefault);

        // Meta kirim ke antrean tanda tangan (doc.simtkd.com) - opsional.
        // Dipasang lewat opsi o.dokumen = { jenis, refId, nomor, tanggal, url }.
        var ttdMeta = null;
        if (o.dokumen && o.dokumen.url) {
            ttdMeta = {
                url: String(o.dokumen.url),
                jenis: String(o.dokumen.jenis || ''),
                ref_id: (o.dokumen.refId != null && o.dokumen.refId !== '') ? String(o.dokumen.refId) : '',
                nomor: String(o.dokumen.nomor || ''),
                judul: String(o.judul || ''),
                tanggal: String(o.dokumen.tanggal || '')
            };
        }
        // HTML dokumen mandiri (CSS tertanam) untuk dikirim ke antrean TTD
        var ttdDocHtml = ttdMeta
            ? '<style>' + CSS + '</style><div id="docArea">' + docHtml + '</div>'
            : '';

        // Panel identitas penandatangan manual (opsional, di atas tombol cetak).
        // Dipasang lewat opsi o.identitas = { label, labelNip, placeholder }.
        var identHtml = o.identitas
            ? '<div class="cs-ident">' +
                '<div class="cs-ident-title"><i class="fa fa-user-edit"></i> Identitas Penandatangan</div>' +
                '<div class="cs-grid">' +
                    '<div class="cs-field"><label for="csIdentNama">' + esc(o.identitas.label || 'Nama Penandatangan') + '</label>' +
                        '<input type="text" id="csIdentNama" data-ident="nama" placeholder="' + esc(o.identitas.placeholder || 'Nama lengkap') + '"></div>' +
                    '<div class="cs-field"><label for="csIdentNip">' + esc(o.identitas.labelNip || 'NIP') + '</label>' +
                        '<input type="text" id="csIdentNip" data-ident="nip" placeholder="NIP"></div>' +
                '</div>' +
            '</div>'
            : '';

        var panel =
            '<div class="cetak-settings no-print">' +
                '<div class="cs-head"><i class="fa fa-sliders-h"></i> Pengaturan Laman Cetak</div>' +
                identHtml +
                '<div class="cs-grid">' +
                    '<div class="cs-field"><label for="csFontSize">Ukuran Font</label><input type="number" id="csFontSize" min="8" max="30" value="13"></div>' +
                    '<div class="cs-field"><label for="csLineHeight">Jarak Tulisan</label><input type="number" id="csLineHeight" min="1" max="3" step="0.1" value="1.6"></div>' +
                    '<div class="cs-field"><label for="csFont">Font</label><select id="csFont">' + fontOpts + '</select></div>' +
                    '<div class="cs-field"><label for="csLineWidth">Ketebalan Garis</label><input type="number" id="csLineWidth" min="0" max="5" step="0.5" value="1"></div>' +
                    '<div class="cs-field"><label for="csLineColor">Warna Garis</label><div class="cs-color"><input type="color" id="csLineColor" value="#000000"><span id="csLineColorVal">#000000</span></div></div>' +
                    '<div class="cs-field"><label for="csTextColor">Warna Tulisan</label><div class="cs-color"><input type="color" id="csTextColor" value="#000000"><span id="csTextColorVal">#000000</span></div></div>' +
                    '<div class="cs-field"><label>Orientasi</label><div class="cs-toggle" id="csOrient"><button type="button" data-o="portrait" class="active">Potret</button><button type="button" data-o="landscape">Lanskap</button></div></div>' +
                '</div>' +
                '<div class="cs-actions">' +
                    '<button type="button" class="cs-print" onclick="window.print()">&#128424; Cetak / Simpan PDF</button>' +
                    (ttdMeta ? '<button type="button" class="cs-ttd" id="csKirimTtd" onclick="csKirimTtd()">&#9998; Kirim ke Tanda Tangan</button>' : '') +
                    '<button type="button" class="cs-close" onclick="window.close()">Tutup</button>' +
                    (ttdMeta ? '<div class="cs-ttd-status" id="csTtdStatus"></div>' : '') +
                '</div>' +
                '<div class="cs-hint">Setelan tersimpan otomatis dan dipakai untuk dokumen berikutnya.</div>' +
            '</div>';

        var html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">' +
            '<title>' + esc(o.title || o.judul || 'Dokumen') + '</title>' +
            '<style id="pageStyle">@page { size: A4 portrait; margin: 14mm; }</style>' +
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">' +
            '<style>' + CSS + '</style></head><body>' +
            panel +
            '<div id="docArea">' + docHtml + '</div>' +
            (ttdMeta ? '<script>var CS_TTD_META=' + JSON.stringify(ttdMeta).replace(/<\//g, '<\\/') +
                ';var CS_TTD_HTML=' + JSON.stringify(ttdDocHtml).replace(/<\//g, '<\\/') + ';<\/script>' : '') +
            '<script>' + PANEL_JS + '<\/script>' +
            '</body></html>';

        w.document.write(html);
        w.document.close();
        w.focus();
    }

    global.CetakBelanja = {
        bukaCetak: bukaCetak,
        esc: esc,
        rupiah: rupiah,
        tgl: tgl,
        tglPanjang: tglPanjang,
        instansi: instansi,
        namaAkun: namaAkun,
        fieldRow: fieldRow,
        detailTable: detailTable,
        ttd: ttd,
        terbilang: terbilang,
        terbilangRupiah: terbilangRupiah,
        blokTitle: blokTitle,
        paragraf: paragraf,
        kepada: kepada,
        itemLine: itemLine,
        ttdTunggal: ttdTunggal,
        suratPengantar: suratPengantar,
        ringkasanBlok: ringkasanBlok,
        rincianTable: rincianTable,
        ringkasanDpaTable: ringkasanDpaTable
    };
})(window);
