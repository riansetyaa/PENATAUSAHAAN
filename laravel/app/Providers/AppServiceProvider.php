<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Batas token API: 12 jam. Menggantikan token legacy yang tidak
        // punya masa berlaku dan tersimpan di kolom `users.api_token`.
        //
        // Dengan masa berlaku, token yang bocor (mis. lewat log server)
        // hanya berlaku singkat sehingga jauh lebih kecil risikonya.
        config([
            'sanctum.expiration' => 12 * 60,
        ]);

        // Paksa timezone Asia/Jakarta agar perhitungan OTP, tanggal anggaran,
        // dan periode laporan konsisten dengan zona waktu pengguna
        // (sistem legacy memakai date() pada server).
        config([
            'app.timezone' => 'Asia/Jakarta',
        ]);
    }
}
