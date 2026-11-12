<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `spp_detail` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spp_detail')) {
            return;
        }

        Schema::create('spp_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->nullable();
            $table->unsignedInteger('spp_id')->nullable();
            $table->string('kode_rekening', 50)->nullable();
            $table->string('uraian', 255)->nullable();
            $table->decimal('jumlah', 18, 2)->nullable()->default(0.00);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['spp_id'], 'idx_sd_spp');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
