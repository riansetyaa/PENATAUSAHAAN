<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `dokumen_ttd` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dokumen_ttd')) {
            return;
        }

        Schema::create('dokumen_ttd', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('dokumen_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedTinyInteger('urutan')->nullable()->default(1);
            $table->string('jabatan', 100)->nullable();
            $table->string('nama', 100)->nullable();
            $table->string('nip', 50)->nullable();
            $table->enum('status', ['menunggu', 'ditandatangani', 'ditolak'])->nullable()->default('menunggu');
            $table->dateTime('signed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unique(['dokumen_id', 'urutan'], 'uq_dttd_urutan');
            $table->index(['dokumen_id'], 'idx_dttd_dokumen');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
