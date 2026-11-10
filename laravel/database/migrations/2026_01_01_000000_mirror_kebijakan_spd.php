<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `kebijakan_spd` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kebijakan_spd')) {
            return;
        }

        Schema::create('kebijakan_spd', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->nullable();
            $table->string('jenis_penerbitan', 100)->nullable();
            $table->string('jenis_periode', 100)->nullable();
            $table->date('tanggal')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->nullable()->default('aktif');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['skpd'], 'idx_kspd_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
