<?php

declare(strict_types=1);

use App\Http\Controllers\Web\AklapWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute web (Blade) — Fase 4 migrasi bertahap
|--------------------------------------------------------------------------
|
| Halaman dipindahkan satu per satu dari frontend vanilla ke Laravel. Selama
| proses berjalan, menu tetap menautkan halaman yang belum diporting ke file
| legacy-nya (lihat config/aklap_menu.php), sehingga pengguna tidak pernah
| kehilangan akses.
|
| Autentikasi: sesi Laravel (`auth`). Tamu yang membuka halaman /aklap/*
| diarahkan ke /masuk (lihat redirectGuestsTo pada bootstrap/app.php).
|
*/

Route::redirect('/', '/aklap')->name('beranda');

Route::middleware('guest')->group(function (): void {
    Route::get('/masuk', [AklapWebController::class, 'formulirMasuk'])->name('masuk');
    Route::post('/masuk', [AklapWebController::class, 'masuk'])->name('masuk.simpan');
});

Route::post('/keluar', [AklapWebController::class, 'keluar'])->middleware('auth')->name('keluar');

Route::middleware('auth')->prefix('aklap')->name('aklap.')->group(function (): void {
    Route::get('/', [AklapWebController::class, 'beranda'])->name('beranda');

    Route::get('buku-pengeluaran', [AklapWebController::class, 'bukuPengeluaran'])
        ->name('pengeluaran');
});
