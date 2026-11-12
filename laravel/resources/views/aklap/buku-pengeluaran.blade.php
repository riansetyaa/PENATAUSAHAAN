@extends('layouts.aklap')

@section('title', 'Buku Kas Pengeluaran PPKD')
@section('menu-aktif', 'aklap.pengeluaran')
@section('crumb-grup', 'Buku Kas')
@section('crumb', 'Buku Kas Pengeluaran PPKD')

@section('content')
    <div class="page-head">
        <div>
            <h1>Buku Kas Pengeluaran PPKD</h1>
            <p>Catat transaksi kas keluar (pengeluaran) pada PPKD — halaman contoh hasil porting ke Laravel.</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <span class="badge badge-info"><i class="fas fa-code-branch"></i> Laravel</span>
            <a class="btn btn-muted" href="{{ url('buku-penerimaan-ppkd.html') }}" title="Halaman legacy (belum diporting)">
                <i class="fas fa-right-left"></i> Buku Kas Penerimaan
            </a>
            <button class="btn btn-primary" id="btnTambah"><i class="fas fa-plus"></i> Input Pengeluaran</button>
        </div>
    </div>

    <div class="card" id="formCard" style="display:none;">
        <div class="card-head"><i class="fas fa-pen-to-square"></i> <span id="formTitle">Input Pengeluaran</span></div>
        <div class="card-body">
            <div class="filter-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="filter-field">
                    <label>Tanggal *</label>
                    <input type="date" id="fTanggal">
                </div>
                <div class="filter-field">
                    <label>No. Bukti</label>
                    <input type="text" id="fNoBukti" placeholder="cth: SP2D-001" autocomplete="off">
                </div>
                <div class="filter-field">
                    <label>Kode Rekening</label>
                    <input type="text" id="fKode" placeholder="cth: 5.1.2.01" autocomplete="off">
                </div>
                <div class="filter-field" style="grid-column: span 2;">
                    <label>Uraian *</label>
                    <input type="text" id="fUraian" placeholder="cth: Belanja ATK" autocomplete="off">
                </div>
                <div class="filter-field">
                    <label>Jumlah (Rp) *</label>
                    <input type="number" id="fJumlah" min="0" step="0.01" placeholder="0">
                </div>
                <div class="filter-field">
                    <label>&nbsp;</label>
                    <div class="filter-actions">
                        <button class="btn btn-success" id="btnSimpan"><i class="fas fa-floppy-disk"></i> Simpan</button>
                        <button class="btn btn-muted" id="btnBatal"><i class="fas fa-xmark"></i> Batal</button>
                    </div>
                </div>
            </div>
            <p class="muted" style="font-size:12px;margin-top:10px;">
                <i class="fas fa-circle-info"></i>
                Data disimpan ke <code>/api/v1/aklap/buku-kas</code> (endpoint Laravel yang sudah lolos uji paritas).
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <i class="fas fa-list"></i> Daftar Pengeluaran
            <span id="rowCount" style="margin-left:auto;font-weight:500;font-size:12px;color:#6b7280;"></span>
        </div>
        <div class="card-body" style="padding-bottom:0;">
            <div class="filter-grid" style="grid-template-columns: repeat(4, 1fr);">
                <div class="filter-field">
                    <label>Tanggal Awal</label>
                    <input type="date" id="fDari">
                </div>
                <div class="filter-field">
                    <label>Tanggal Akhir</label>
                    <input type="date" id="fSampai">
                </div>
                <div class="filter-field">
                    <label>&nbsp;</label>
                    <div class="filter-actions">
                        <button class="btn btn-dark" id="btnFilter"><i class="fas fa-filter"></i> Terapkan</button>
                        <button class="btn btn-muted" id="btnReset"><i class="fas fa-rotate-left"></i> Reset</button>
                    </div>
                </div>
            </div>

            <div class="sum-grid" style="margin-top:16px;">
                <div class="sum-card"><span>Jumlah Transaksi</span><b id="sumBaris">0</b></div>
                <div class="sum-card"><span>Total Pengeluaran</span><b id="sumKeluar">Rp 0</b></div>
                <div class="sum-card"><span>Saldo Akhir</span><b id="sumSaldo">Rp 0</b></div>
                <div class="sum-card"><span>Instansi</span><b style="font-size:13px;">{{ auth()->user()->instansi ?? '-' }}</b></div>
            </div>
        </div>

        <div class="table-wrap" style="margin-top:16px;">
            <table>
                <thead>
                    <tr>
                        <th style="width:46px;">No</th>
                        <th>Tanggal</th>
                        <th>No. Bukti</th>
                        <th>Kode Rekening</th>
                        <th>Uraian</th>
                        <th class="r">Pengeluaran</th>
                        <th class="r">Saldo</th>
                        <th class="c" style="width:96px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tbody">
                    <tr><td colspan="8" class="empty">Memuat data…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('scripts')
<script>
(function () {
    const tbody = document.getElementById('tbody');
    const el = (id) => document.getElementById(id);
    let data = [];

    function kosong(pesan) {
        tbody.innerHTML = '<tr><td colspan="8" class="empty">' + pesan + '</td></tr>';
    }

    function gambar() {
        if (!data.length) {
            kosong('Belum ada transaksi pengeluaran pada periode ini.');
        } else {
            tbody.innerHTML = data.map(function (r, i) {
                return '<tr>' +
                    '<td class="muted">' + (i + 1) + '</td>' +
                    '<td>' + (r.tanggal || '-') + '</td>' +
                    '<td>' + (r.no_bukti || '-') + '</td>' +
                    '<td>' + (r.kode_rekening || '-') + '</td>' +
                    '<td>' + (r.uraian || '-') + '</td>' +
                    '<td class="r">' + rupiah(r.pengeluaran) + '</td>' +
                    '<td class="r">' + rupiah(r.saldo) + '</td>' +
                    '<td class="c">' +
                        '<button class="btn-mini" data-ubah="' + r.id + '" title="Ubah"><i class="fas fa-pen"></i></button> ' +
                        '<button class="btn-mini danger" data-hapus="' + r.id + '" title="Hapus"><i class="fas fa-trash"></i></button>' +
                    '</td>' +
                    '</tr>';
            }).join('');
        }

        el('rowCount').textContent = data.length + ' baris';
        el('sumBaris').textContent = data.length;
    }

    async function muat() {
        const q = new URLSearchParams({ jenis: 'pengeluaran' });

        if (el('fDari').value) { q.set('dari', el('fDari').value); }
        if (el('fSampai').value) { q.set('akhir', el('fSampai').value); }

        kosong('Memuat data…');

        try {
            const d = await aklap.minta('/buku-kas?' + q.toString());
            data = (d.data || []).filter(function (r) { return r.jenis === 'pengeluaran'; });

            el('sumKeluar').textContent = 'Rp ' + rupiah(d.total_pengeluaran);
            el('sumSaldo').textContent = 'Rp ' + rupiah(d.saldo_akhir);
            gambar();
        } catch (e) {
            kosong('Gagal memuat: ' + e.message);
            toast(e.message, 'err');
        }
    }

    function bukaForm(baris) {
        el('formCard').style.display = '';
        el('formTitle').textContent = baris ? 'Ubah Pengeluaran' : 'Input Pengeluaran';
        el('fTanggal').value = baris ? (baris.tanggal || '') : new Date().toISOString().slice(0, 10);
        el('fNoBukti').value = baris ? (baris.no_bukti || '') : '';
        el('fKode').value = baris ? (baris.kode_rekening || '') : '';
        el('fUraian').value = baris ? (baris.uraian || '') : '';
        el('fJumlah').value = baris ? Number(baris.jumlah) : '';
        el('formCard').dataset.id = baris ? baris.id : '';
        el('fTanggal').focus();
    }

    function tutupForm() {
        el('formCard').style.display = 'none';
        el('formCard').dataset.id = '';
    }

    async function simpan() {
        const id = el('formCard').dataset.id;
        const isi = {
            tanggal: el('fTanggal').value,
            jenis: 'pengeluaran',
            no_bukti: el('fNoBukti').value.trim(),
            kode_rekening: el('fKode').value.trim(),
            uraian: el('fUraian').value.trim(),
            jumlah: el('fJumlah').value
        };

        if (id) { isi.id = Number(id); }

        try {
            const d = await aklap.minta('/buku-kas', { method: 'POST', body: isi });
            toast(d.message || 'Tersimpan.', 'ok');
            tutupForm();
            muat();
        } catch (e) {
            toast(e.message, 'err');
        }
    }

    async function hapus(id) {
        if (!confirm('Hapus transaksi ini?')) { return; }

        try {
            const d = await aklap.minta('/buku-kas/hapus', { method: 'POST', body: { id: Number(id) } });
            toast(d.message || 'Terhapus.', 'ok');
            muat();
        } catch (e) {
            toast(e.message, 'err');
        }
    }

    el('btnTambah').addEventListener('click', function () { bukaForm(null); });
    el('btnBatal').addEventListener('click', tutupForm);
    el('btnSimpan').addEventListener('click', simpan);
    el('btnFilter').addEventListener('click', muat);
    el('btnReset').addEventListener('click', function () {
        el('fDari').value = '';
        el('fSampai').value = '';
        muat();
    });

    tbody.addEventListener('click', function (e) {
        const ubah = e.target.closest('[data-ubah]');
        const hapusBtn = e.target.closest('[data-hapus]');

        if (ubah) {
            const baris = data.find(function (r) { return String(r.id) === ubah.dataset.ubah; });
            if (baris) { bukaForm(baris); }
        }

        if (hapusBtn) { hapus(hapusBtn.dataset.hapus); }
    });

    muat();
})();
</script>
@endsection

