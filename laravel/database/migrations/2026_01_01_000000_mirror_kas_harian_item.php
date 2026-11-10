<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `kas_harian_item` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kas_harian_item')) {
            return;
        }

        Schema::create('kas_harian_item', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('kas_harian_id');
            $table->string('skpd', 150)->default('');
            $table->integer('urut')->default(0);
            $table->string('no_sp2d', 60)->default('');
            $table->string('no_sts', 60)->default('');
            $table->string('no_lain', 60)->default('');
            $table->string('uraian', 255)->default('');
            $table->decimal('penerimaan', 18, 2)->default(0.00);
            $table->decimal('pengeluaran', 18, 2)->default(0.00);
            $table->index(['kas_harian_id'], 'idx_khi_head');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
