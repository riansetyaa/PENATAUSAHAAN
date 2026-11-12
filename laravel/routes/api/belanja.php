<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Belanja\BesaranUpController;
use App\Http\Controllers\Api\V1\Belanja\KebijakanSpdController;
use App\Http\Controllers\Api\V1\Belanja\LaporanBelanjaController;
use App\Http\Controllers\Api\V1\Belanja\LpjController;
use App\Http\Controllers\Api\V1\Belanja\NpdController;
use App\Http\Controllers\Api\V1\Belanja\PengajuanTuController;
use App\Http\Controllers\Api\V1\Belanja\RekananController;
use App\Http\Controllers\Api\V1\Belanja\RekeningSkpdController;
use App\Http\Controllers\Api\V1\Belanja\RingkasanBelanjaController;
use App\Http\Controllers\Api\V1\Belanja\Sp2dController;
use App\Http\Controllers\Api\V1\Belanja\SpdController;
use App\Http\Controllers\Api\V1\Belanja\SpmController;
use App\Http\Controllers\Api\V1\Belanja\SppController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modul Belanja (v1)
|--------------------------------------------------------------------------
|
| Diporting dari api/belanja.php pada sistem legacy.
|
| Porting berjalan BERTAHAP (strangler fig): pada fase ini hanya endpoint
| BACA (GET) yang sudah diporting. Seluruh mutasi (create/otorisasi/
| verifikasi/persentujuan/pencairan) masih dilayani sistem legacy sehingga
| memori alur kerja tidak berubah sampai semuanya selesai.
|
| Pendekatan ini aman karena kedua sistem memakai BASIS DATA YANG SAMA:
| data yang ditulis legacy langsung terlihat oleh endpoint baca baru.
|
| Seluruh endpoint berada di bawah middleware `auth:sanctum` (wajib login)
| dan `tenant` (wajib punya instansi/SKPD — fail-closed).
|
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('belanja')->group(function () {

    // ---- Ringkasan & master ----
    Route::get('summary', [RingkasanBelanjaController::class, 'summary'])
        ->name('api.v1.belanja.summary');

    Route::get('rekanan', [RekananController::class, 'index'])
        ->name('api.v1.belanja.rekanan');

    Route::get('rekening-skpd', [RekeningSkpdController::class, 'index'])
        ->name('api.v1.belanja.rekening-skpd');

    Route::get('kebijakan-spd', [KebijakanSpdController::class, 'index'])
        ->name('api.v1.belanja.kebijakan-spd');

    Route::get('besaran-up', [BesaranUpController::class, 'show'])
        ->name('api.v1.belanja.besaran-up');

    // ---- Alur: SPD -> SPP -> SPM -> SP2D ----
    Route::get('spd', [SpdController::class, 'index'])
        ->name('api.v1.belanja.spd');

    Route::get('spd-otor', [SpdController::class, 'daftarOtorisasi'])
        ->name('api.v1.belanja.spd-otor');

    Route::get('spp', [SppController::class, 'index'])
        ->name('api.v1.belanja.spp');

    Route::get('spm', [SpmController::class, 'index'])
        ->name('api.v1.belanja.spm');

    Route::get('spm-ver', [SpmController::class, 'daftarVerifikasi'])
        ->name('api.v1.belanja.spm-ver');

    Route::get('sp2d', [Sp2dController::class, 'index'])
        ->name('api.v1.belanja.sp2d');

    // ---- LPJ, Pengajuan TU, NPD, laporan ----
    Route::get('lpj', [LpjController::class, 'index'])
        ->name('api.v1.belanja.lpj');

    Route::get('pengajuan-tu', [PengajuanTuController::class, 'index'])
        ->name('api.v1.belanja.pengajuan-tu');

    Route::get('npd', [NpdController::class, 'index'])
        ->name('api.v1.belanja.npd');

    Route::get('bku', [LaporanBelanjaController::class, 'bku'])
        ->name('api.v1.belanja.bku');

    // =================================================================
    // Mutasi (create + alur kerja)
    // =================================================================

    // ---- Master & referensi ----
    Route::post('rekanan', [RekananController::class, 'store'])
        ->name('api.v1.belanja.rekanan.store');

    Route::post('rekening-skpd', [RekeningSkpdController::class, 'store'])
        ->name('api.v1.belanja.rekening-skpd.store');

    Route::post('rekening-skpd/{id}/ajukan', [RekeningSkpdController::class, 'ajukan'])
        ->name('api.v1.belanja.rekening-skpd.ajukan');

    Route::post('rekening-skpd/{id}/nomor', [RekeningSkpdController::class, 'nomor'])
        ->name('api.v1.belanja.rekening-skpd.nomor');

    Route::post('rekening-skpd/{id}/validasi', [RekeningSkpdController::class, 'validasi'])
        ->name('api.v1.belanja.rekening-skpd.validasi');

    Route::post('besaran-up', [BesaranUpController::class, 'simpan'])
        ->name('api.v1.belanja.besaran-up.simpan');

    Route::post('kebijakan-spd', [KebijakanSpdController::class, 'store'])
        ->name('api.v1.belanja.kebijakan-spd.store');

    Route::post('lpj', [LpjController::class, 'store'])
        ->name('api.v1.belanja.lpj.store');

    Route::post('pengajuan-tu', [PengajuanTuController::class, 'store'])
        ->name('api.v1.belanja.pengajuan-tu.store');

    Route::post('pengajuan-tu/{id}/otorisasi', [PengajuanTuController::class, 'otorisasi'])
        ->name('api.v1.belanja.pengajuan-tu.otorisasi');

    Route::post('pengajuan-tu/{id}/validasi', [PengajuanTuController::class, 'validasi'])
        ->name('api.v1.belanja.pengajuan-tu.validasi');

    Route::post('npd', [NpdController::class, 'store'])
        ->name('api.v1.belanja.npd.store');

    Route::post('npd/{id}/validasi-pa', [NpdController::class, 'validasiPa'])
        ->name('api.v1.belanja.npd.validasi-pa');

    Route::post('npd/{id}/validasi-bp', [NpdController::class, 'validasiBp'])
        ->name('api.v1.belanja.npd.validasi-bp');

    // ---- Alur: SPD -> SPP -> SPM -> SP2D ----
    Route::post('spd', [SpdController::class, 'store'])
        ->name('api.v1.belanja.spd.store');

    Route::post('spd/{id}/otorisasi', [SpdController::class, 'otorisasi'])
        ->name('api.v1.belanja.spd.otorisasi');

    Route::post('spp', [SppController::class, 'store'])
        ->name('api.v1.belanja.spp.store');

    Route::post('spp/{id}/verifikasi', [SppController::class, 'verifikasi'])
        ->name('api.v1.belanja.spp.verifikasi');

    Route::post('spm/{id}/persetujuan', [SpmController::class, 'persetujuan'])
        ->name('api.v1.belanja.spm.persetujuan');

    Route::post('spm/{id}/verifikasi', [SpmController::class, 'verifikasi'])
        ->name('api.v1.belanja.spm.verifikasi');

    Route::post('sp2d', [Sp2dController::class, 'store'])
        ->name('api.v1.belanja.sp2d.store');

    Route::post('sp2d/{id}/verifikasi', [Sp2dController::class, 'verifikasi'])
        ->name('api.v1.belanja.sp2d.verifikasi');

    Route::post('sp2d/{id}/pencairan', [Sp2dController::class, 'pencairan'])
        ->name('api.v1.belanja.sp2d.pencairan');
});
