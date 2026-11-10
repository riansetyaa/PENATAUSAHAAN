<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `rekonsiliasi_bank_item` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rekonsiliasi_bank_item')) {
            return;
        }

        Schema::create('rekonsiliasi_bank_item', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('rekonsiliasi_id');
            $table->string('skpd', 150)->default('');
            $table->enum('bagian', ['A', 'B', 'C', 'D'])->default('A');
            $table->integer('urut')->default(0);
            $table->string('uraian', 255)->default('');
            $table->decimal('nilai', 18, 2)->default(0.00);
            $table->index(['rekonsiliasi_id'], 'idx_rbi_head');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
