<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AktivasiController;
use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API SIM-TKD (v1)
|--------------------------------------------------------------------------
|
| Satu aplikasi melayani tiga subdomain:
|   simtkd.com    -> modul utama (penerimaan, belanja, pengaturan)
|   peta.simtkd.com -> modul AKLAP (akuntansi)
|   doc.simtkd.com  -> modul dokumen & tanda tangan elektronik
|
| Seluruh modul berada di bawah prefix /api/v1 agar versi berikutnya
| dapat berjalan berdampingan.
|
*/

Route::prefix('v1')->group(function () {

    // -----------------------------------------------------------------
    // Publik — autentikasi
    // -----------------------------------------------------------------
    // Group 'api-session' membawa cookie session sehingga frontend utama
    // (yang memakai session cookie) tetap bekerja seperti sistem legacy.
    Route::middleware('api-session')->prefix('auth')->group(function () {
        // CATATAN: TIDAK memakai middleware `throttle` bawaan Laravel.
        //
        // Rate limit legacy diterapkan DI DALAM controller dengan kunci
        // 'username+IP' (8 percobaan / 5 menit) dan pesan error yang sama
        // dengan sistem lama. Middleware throttle bawaan memakai kunci IP
        // dan membalas 429 dengan bentuk JSON berbeda, sehingga hasil
        // endpoint berbeda dari legacy (ditemukan oleh differential test).
        //
        // Endpoint publik tetap dilindungi: limit di dalam controller
        // berlaku persis seperti versi legacy.

        Route::post('login', [AuthController::class, 'login'])
            ->name('api.v1.auth.login');

        Route::post('register', [AuthController::class, 'register'])
            ->name('api.v1.auth.register');

        Route::get('logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('api.v1.auth.logout');

        // POST ikut didukung karena logout yang mengubah state lebih aman
        // bila dipanggil lewat POST (tidak bisa dipicu oleh <img> di halaman lain).
        Route::post('logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('api.v1.auth.logout.post');

        Route::get('session', [AuthController::class, 'session'])
            ->middleware('auth:sanctum')
            ->name('api.v1.auth.session');
    });

    // -----------------------------------------------------------------
    // Publik — aktivasi akun (OTP)
    // -----------------------------------------------------------------
    Route::middleware('api-session')->prefix('aktivasi')->group(function () {
        // Sama seperti auth: rate limit di dalam controller (legacy-faithful).
        Route::post('cek', [AktivasiController::class, 'cek'])
            ->name('api.v1.aktivasi.cek');

        Route::post('kirim-otp', [AktivasiController::class, 'kirimOtp'])
            ->name('api.v1.aktivasi.kirim-otp');

        Route::post('verifikasi', [AktivasiController::class, 'verifikasi'])
            ->name('api.v1.aktivasi.verifikasi');
    });

    // -----------------------------------------------------------------
    // Terlindungi — wajib login
    // -----------------------------------------------------------------
    Route::middleware(['auth:sanctum'])->group(function () {

        // Modul-modul berikutnya (penerimaan, belanja, AKLAP, dokumen)
        // ditambahkan pada file route terpisah agar mudah dinavigasi:
        //   routes/api/penerimaan.php
        //   routes/api/belanja.php
        //   routes/api/aklap.php
        //   routes/api/dokumen.php
    });

    // -----------------------------------------------------------------
    // Modul Penerimaan
    // -----------------------------------------------------------------
    require __DIR__ . '/api/penerimaan.php';
});
