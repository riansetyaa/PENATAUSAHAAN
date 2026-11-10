<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `spd` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spd')) {
            return;
        }

        Schema::create('spd', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('skpd', 150)->nullable();
            $table->string('nomor_spd', 50);
            $table->date('tanggal')->nullable();
            $table->string('jenis', 50)->nullable();
            $table->string('periode', 50)->nullable();
            $table->decimal('jumlah', 18, 2)->nullable()->default(0.00);
            $table->enum('status', ['belum_otorisasi', 'sudah_otorisasi'])->nullable()->default('belum_otorisasi');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->index(['skpd'], 'idx_spd_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
