<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Aklap\AkunController;
use App\Http\Controllers\Api\V1\Aklap\AkunMasterController;
use App\Http\Controllers\Api\V1\Aklap\AnggaranController;
use App\Http\Controllers\Api\V1\Aklap\DokumenController;
use App\Http\Controllers\Api\V1\Aklap\DokumenKirimController;
use App\Http\Controllers\Api\V1\Aklap\JurnalController;
use App\Http\Controllers\Api\V1\Aklap\JurnalKhususController;
use App\Http\Controllers\Api\V1\Aklap\JurnalUmumController;
use App\Http\Controllers\Api\V1\Aklap\KasController;
use App\Http\Controllers\Api\V1\Aklap\LaporanAkuntansiController;
use App\Http\Controllers\Api\V1\Aklap\LaporanController;
use App\Http\Controllers\Api\V1\Aklap\ProfilController;
use App\Http\Controllers\Api\V1\Aklap\RekapController;
use App\Http\Controllers\Api\V1\Aklap\RekonsiliasiBankController;
use App\Http\Controllers\Api\V1\Aklap\RegisterPpkdController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modul AKLAP (v1) — peta.simtkd.com
|--------------------------------------------------------------------------
|
| Diporting dari peta.simtkd.com/api/aklap.php (dan menyusul
| api/akuntansi.php). Porting berjalan BERTAHAP (strangler fig): fase ini
| mencakup seluruh AKSI BACA; mutasi (approve/reject jurnal, jurnal biasa
| simpan/hapus) menyusul.
|
| Autentikasi: frontend AKLAP memakai token API (?token= / Bearer). Proksi
| cutover menerjemahkan token tersebut menjadi tiket SSO sehingga endpoint
| di sini cukup memakai `auth:sanctum` seperti modul lain.
|
| Seluruh endpoint di bawah `auth:sanctum` (wajib login) dan `tenant`
| (wajib punya instansi/SKPD — fail-closed).
|
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('aklap')->group(function () {

    Route::get('jurnal', [JurnalController::class, 'index'])
        ->name('api.v1.aklap.jurnal');

    Route::get('jurnal-biasa', [JurnalController::class, 'jurnalBiasa'])
        ->name('api.v1.aklap.jurnal-biasa');

    Route::get('akun', [AkunController::class, 'index'])
        ->name('api.v1.aklap.akun');

    Route::get('dokumen', [DokumenController::class, 'index'])
        ->name('api.v1.aklap.dokumen');

    Route::get('lra', [LaporanController::class, 'lra'])
        ->name('api.v1.aklap.lra');

    Route::get('neraca', [LaporanController::class, 'neraca'])
        ->name('api.v1.aklap.neraca');

    Route::get('profil', [ProfilController::class, 'show'])
        ->name('api.v1.aklap.profil');

    Route::get('rekap', [RekapController::class, 'index'])
        ->name('api.v1.aklap.rekap');

    // =================================================================
    // Akuntansi manual (peta.simtkd.com/api/akuntansi.php) — bertahap
    // =================================================================

    // ---- Master Akun (COA per instansi) ----
    Route::get('akun-master', [AkunMasterController::class, 'index'])
        ->name('api.v1.aklap.akun-master');

    Route::post('akun-master', [AkunMasterController::class, 'store'])
        ->name('api.v1.aklap.akun-master.store');

    Route::post('akun-master/hapus', [AkunMasterController::class, 'destroy'])
        ->name('api.v1.aklap.akun-master.hapus');

    Route::post('akun-master/seed-coa', [AkunMasterController::class, 'seedCoaAction'])
        ->name('api.v1.aklap.akun-master.seed-coa');

    // ---- Anggaran (pagu LRA berbasis Master Akun) ----
    Route::get('anggaran', [AnggaranController::class, 'index'])
        ->name('api.v1.aklap.anggaran');

    Route::post('anggaran', [AnggaranController::class, 'store'])
        ->name('api.v1.aklap.anggaran.store');

    Route::post('anggaran/hapus', [AnggaranController::class, 'destroy'])
        ->name('api.v1.aklap.anggaran.hapus');

    Route::post('anggaran/sinkron-master', [AnggaranController::class, 'sinkronMaster'])
        ->name('api.v1.aklap.anggaran.sinkron-master');

    // ---- Profil akuntansi (NIP + gambar TTD) ----
    Route::get('profil-akuntansi', [ProfilController::class, 'akuntansi'])
        ->name('api.v1.aklap.profil-akuntansi');

    // ---- Jurnal umum (double-entry) ----
    Route::get('jurnal-umum', [JurnalUmumController::class, 'index'])
        ->name('api.v1.aklap.jurnal-umum');

    Route::post('jurnal-umum', [JurnalUmumController::class, 'store'])
        ->name('api.v1.aklap.jurnal-umum.store');

    Route::post('jurnal-umum/hapus', [JurnalUmumController::class, 'destroy'])
        ->name('api.v1.aklap.jurnal-umum.hapus');

    Route::post('jurnal-umum/status', [JurnalUmumController::class, 'status'])
        ->name('api.v1.aklap.jurnal-umum.status');

    // ---- Saran jurnal dari dokumen (STS / SP2D) ----
    Route::get('saran-dokumen', [JurnalUmumController::class, 'saranDokumen'])
        ->name('api.v1.aklap.saran-dokumen');

    Route::get('saran-jurnal', [JurnalUmumController::class, 'saranJurnal'])
        ->name('api.v1.aklap.saran-jurnal');

    // ---- Laporan akuntansi (baca-saja, sumber: jurnal approved & buku kas) ----
    Route::get('buku-besar', [LaporanAkuntansiController::class, 'bukuBesar'])
        ->name('api.v1.aklap.buku-besar');

    Route::get('neraca-saldo', [LaporanAkuntansiController::class, 'neracaSaldo'])
        ->name('api.v1.aklap.neraca-saldo');

    Route::get('buku-kas', [LaporanAkuntansiController::class, 'bukuKas'])
        ->name('api.v1.aklap.buku-kas');

    Route::get('buku-kas-umum', [LaporanAkuntansiController::class, 'bukuKasUmum'])
        ->name('api.v1.aklap.buku-kas-umum');

    Route::get('buku-pembantu', [LaporanAkuntansiController::class, 'bukuPembantu'])
        ->name('api.v1.aklap.buku-pembantu');

    Route::get('buku-pembantu-obyek', [LaporanAkuntansiController::class, 'bukuPembantuObyek'])
        ->name('api.v1.aklap.buku-pembantu-obyek');

    // ---- Mutasi buku kas (entri manual penerimaan/pengeluaran) ----
    Route::post('buku-kas', [KasController::class, 'bukuKasStore'])
        ->name('api.v1.aklap.buku-kas.store');

    Route::post('buku-kas/hapus', [KasController::class, 'bukuKasDestroy'])
        ->name('api.v1.aklap.buku-kas.hapus');

    // ---- Laporan Posisi Kas Harian (BUD) ----
    Route::get('kas-harian', [KasController::class, 'kasHarian'])
        ->name('api.v1.aklap.kas-harian');

    Route::get('kas-harian/daftar', [KasController::class, 'kasHarianDaftar'])
        ->name('api.v1.aklap.kas-harian.daftar');

    Route::post('kas-harian', [KasController::class, 'kasHarianSimpan'])
        ->name('api.v1.aklap.kas-harian.store');

    Route::post('kas-harian/hapus', [KasController::class, 'kasHarianHapus'])
        ->name('api.v1.aklap.kas-harian.hapus');

    // ---- Rekonsiliasi Bank (BUD, satu dokumen per bulan) ----
    Route::get('rekonsiliasi-bank', [RekonsiliasiBankController::class, 'show'])
        ->name('api.v1.aklap.rekonsiliasi-bank');

    Route::get('rekonsiliasi-bank/daftar', [RekonsiliasiBankController::class, 'index'])
        ->name('api.v1.aklap.rekonsiliasi-bank.daftar');

    Route::post('rekonsiliasi-bank', [RekonsiliasiBankController::class, 'store'])
        ->name('api.v1.aklap.rekonsiliasi-bank.store');

    Route::post('rekonsiliasi-bank/hapus', [RekonsiliasiBankController::class, 'destroy'])
        ->name('api.v1.aklap.rekonsiliasi-bank.hapus');

    // ---- Register SPP/SPM/SP2D PPKD (turunan Buku Pengeluaran PPKD) ----
    Route::get('register-ppkd', [RegisterPpkdController::class, 'registerList'])
        ->name('api.v1.aklap.register-ppkd');

    Route::post('register-ppkd', [RegisterPpkdController::class, 'registerSimpan'])
        ->name('api.v1.aklap.register-ppkd.store');

    // ---- LPJ Bendahara Pengeluaran PPKD (blanko Modul-3E-LK) ----
    Route::get('lpj-ppkd', [RegisterPpkdController::class, 'lpj'])
        ->name('api.v1.aklap.lpj-ppkd');

    // ---- Buku Jurnal Khusus (blanko Modul-3E-LK, diisi manual) ----
    Route::get('jurnal-khusus/template', [JurnalKhususController::class, 'template'])
        ->name('api.v1.aklap.jurnal-khusus.template');

    Route::get('jurnal-khusus', [JurnalKhususController::class, 'show'])
        ->name('api.v1.aklap.jurnal-khusus');

    Route::get('jurnal-khusus/daftar', [JurnalKhususController::class, 'index'])
        ->name('api.v1.aklap.jurnal-khusus.daftar');

    Route::post('jurnal-khusus', [JurnalKhususController::class, 'store'])
        ->name('api.v1.aklap.jurnal-khusus.store');

    Route::post('jurnal-khusus/hapus', [JurnalKhususController::class, 'destroy'])
        ->name('api.v1.aklap.jurnal-khusus.hapus');

    // ---- Kirim dokumen ke antrean tanda tangan (doc.simtkd.com) ----
    Route::post('dokumen-kirim', [DokumenKirimController::class, 'store'])
        ->name('api.v1.aklap.dokumen-kirim');
});
