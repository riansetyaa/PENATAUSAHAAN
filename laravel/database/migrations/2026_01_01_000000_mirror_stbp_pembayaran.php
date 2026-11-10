<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `stbp_pembayaran` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stbp_pembayaran')) {
            return;
        }

        Schema::create('stbp_pembayaran', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('stbp_id');
            $table->string('metode_penyetoran', 20)->default('non_tunai');
            $table->string('nama_penyetor', 150)->default('');
            $table->string('nama_bank', 100)->default('');
            $table->string('nomor_rekening', 50)->default('');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['stbp_id'], 'idx_stbp');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
