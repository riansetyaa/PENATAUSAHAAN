<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `besaran_up` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('besaran_up')) {
            return;
        }

        Schema::create('besaran_up', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->nullable();
            $table->string('tahun', 10)->nullable();
            $table->decimal('besaran_up', 18, 2)->nullable()->default(0.00);
            $table->decimal('besaran_up_kkpd', 18, 2)->nullable()->default(0.00);
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->index(['skpd'], 'idx_bu_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
