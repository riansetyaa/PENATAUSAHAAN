<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Dokumen\DokumenController;
use App\Http\Controllers\Api\V1\Dokumen\TtdController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modul Dokumen & Tanda Tangan Elektronik (v1)
|--------------------------------------------------------------------------
|
| Sisi aplikasi utama: mengirim dokumen hasil cetakan ke antrean TTD.
| Sisi subdomain dokumen: melihat, menandatangani, dan memverifikasi.
|
| Pengaman yang dipertahankan:
|  - Endpoint `verify` bersifat PUBLIK (untuk cek keaslian) dan rate-limited.
|  - Seluruh endpoint lain memakai token Sanctum.
|  - Query dokumen dibatasi ke user_id pemilik (dokumen bersifat pribadi).
|  - Integritas dokumen diperiksa dengan hash_equals() sebelum ditandatangani.
|
*/

// ---- Publik: verifikasi keaslian dokumen ----
Route::get('dokumen/verify', [TtdController::class, 'verify'])
    ->name('api.v1.dokumen.verify');

// ---- Terlindungi ----
Route::middleware(['auth:sanctum'])->group(function () {

    // Sisi app utama: kirim dokumen ke antrean tanda tangan.
    Route::post('dokumen/kirim', [DokumenController::class, 'kirim'])
        ->middleware('tenant')
        ->name('api.v1.dokumen.kirim');

    // Sisi subdomain dokumen.
    Route::get('dokumen/me', [TtdController::class, 'me'])
        ->name('api.v1.dokumen.me');

    Route::get('dokumen/list', [TtdController::class, 'list'])
        ->name('api.v1.dokumen.list');

    Route::get('dokumen/detail', [TtdController::class, 'detail'])
        ->name('api.v1.dokumen.detail');

    Route::get('dokumen/konten', [TtdController::class, 'konten'])
        ->name('api.v1.dokumen.konten');

    Route::get('dokumen/ttd-gambar-saya', [TtdController::class, 'ttdGambarSaya'])
        ->name('api.v1.dokumen.ttd-gambar-saya');

    Route::post('dokumen/ttd-gambar', [TtdController::class, 'ttdGambar'])
        ->name('api.v1.dokumen.ttd-gambar');

    Route::post('dokumen/ttd', [TtdController::class, 'ttd'])
        ->name('api.v1.dokumen.ttd');
});
