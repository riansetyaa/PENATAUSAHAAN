<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Penerimaan\AkunPenerimaanController;
use App\Http\Controllers\Api\V1\Penerimaan\LaporanPenerimaanController;
use App\Http\Controllers\Api\V1\Penerimaan\PermohonanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modul Penerimaan (v1)
|--------------------------------------------------------------------------
|
| Diporting dari api/permohonan.php, api/akun_penerimaan.php, dan
| api/laporan.php pada sistem legacy.
|
| Seluruh endpoint berada di bawah middleware `auth:sanctum` (wajib login)
| dan `tenant` (wajib punya instansi/SKPD — fail-closed).
|
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('penerimaan')->group(function () {

    // ---- Permohonan rekening ----
    Route::get('permohonan', [PermohonanController::class, 'index'])
        ->name('api.v1.penerimaan.permohonan.index');

    Route::post('permohonan', [PermohonanController::class, 'store'])
        ->name('api.v1.penerimaan.permohonan.store');

    Route::post('permohonan/{id}/set-aktif', [PermohonanController::class, 'setAktif'])
        ->whereNumber('id')
        ->name('api.v1.penerimaan.permohonan.set-aktif');

    Route::post('permohonan/{id}/verifikasi', [PermohonanController::class, 'verifikasi'])
        ->whereNumber('id')
        ->name('api.v1.penerimaan.permohonan.verifikasi');

    // ---- Akun penerimaan (Pengaturan) ----
    Route::get('akun', [AkunPenerimaanController::class, 'index'])
        ->name('api.v1.penerimaan.akun.index');

    Route::post('akun', [AkunPenerimaanController::class, 'store'])
        ->name('api.v1.penerimaan.akun.store');

    // ---- Laporan ----
    Route::get('laporan/obyek', [LaporanPenerimaanController::class, 'obyek'])
        ->name('api.v1.penerimaan.laporan.obyek');

    Route::get('laporan/buku-pembantu', [LaporanPenerimaanController::class, 'bukuPembantu'])
        ->name('api.v1.penerimaan.laporan.buku-pembantu');

    Route::get('laporan/rekap', [LaporanPenerimaanController::class, 'rekap'])
        ->name('api.v1.penerimaan.laporan.rekap');

    Route::get('laporan/bku', [LaporanPenerimaanController::class, 'bku'])
        ->name('api.v1.penerimaan.laporan.bku');
});
