<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `users` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nama_lengkap', 100);
            $table->string('nip', 30)->nullable();
            $table->string('username', 50);
            $table->string('email', 100);
            $table->string('password', 255);
            $table->string('instansi', 150)->nullable();
            $table->string('kota', 100)->nullable();
            $table->string('provinsi', 100)->nullable();
            $table->string('api_token', 64)->nullable();
            $table->string('otp_hash', 255)->nullable();
            $table->dateTime('otp_expired_at')->nullable();
            $table->dateTime('otp_terkirim_at')->nullable();
            $table->unsignedTinyInteger('otp_percobaan')->default(0);
            $table->dateTime('otp_verifikasi_at')->nullable();
            $table->enum('peran', ['Admin Dinas', 'Operator', 'Bendahara', 'Verifikator', 'Kepala Dinas', 'Pengguna Umum'])->nullable()->default('Pengguna Umum');
            $table->enum('status', ['pending', 'aktif', 'nonaktif'])->nullable()->default('aktif');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->unique(['email'], 'email');
            $table->unique(['api_token'], 'idx_api_token');
            $table->unique(['username'], 'username');
            $table->index(['peran'], 'idx_peran');
            $table->index(['status'], 'idx_status');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
