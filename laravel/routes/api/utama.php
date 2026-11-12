<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LupaPasswordController;
use App\Http\Controllers\Api\V1\Penerimaan\SkpDaerahController;
use App\Http\Controllers\Api\V1\ProfilController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modul Utama (v1)
|--------------------------------------------------------------------------
|
| Dashboard, Pengaturan (Profil), Lupa Password, dan SKP Daerah.
|
*/

Route::middleware('api-session')->prefix('lupa-password')->group(function () {
    Route::post('cek', [LupaPasswordController::class, 'cek'])
        ->name('api.v1.lupa-password.cek');

    Route::post('kirim-otp', [LupaPasswordController::class, 'kirimOtp'])
        ->name('api.v1.lupa-password.kirim-otp');

    Route::post('reset', [LupaPasswordController::class, 'reset'])
        ->name('api.v1.lupa-password.reset');
});

Route::middleware(['auth:sanctum'])->group(function () {

    // ---- Dashboard ----
    Route::get('dashboard/summary', [DashboardController::class, 'summary'])
        ->middleware('tenant')
        ->name('api.v1.dashboard.summary');

    // ---- Pengaturan > Profil ----
    // Dilengkapi 'api-session' agar perubahan profil langsung tercermin di
    // sidebar/topbar (data profil disimpan di session pada sistem legacy).
    Route::middleware('api-session')->group(function () {
        Route::get('profil', [ProfilController::class, 'show'])
            ->name('api.v1.profil.show');

        Route::post('profil', [ProfilController::class, 'update'])
            ->name('api.v1.profil.update');

        Route::post('profil/password', [ProfilController::class, 'gantiPassword'])
            ->name('api.v1.profil.password');
    });
});

// ---- SKP Daerah (Penerimaan) ----
Route::middleware(['auth:sanctum', 'tenant'])->prefix('penerimaan')->group(function () {
    Route::get('skp', [SkpDaerahController::class, 'index'])
        ->name('api.v1.penerimaan.skp.index');

    Route::post('skp', [SkpDaerahController::class, 'store'])
        ->name('api.v1.penerimaan.skp.store');
});
