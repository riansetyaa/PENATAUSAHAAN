<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `jurnal_khusus_baris` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jurnal_khusus_baris')) {
            return;
        }

        Schema::create('jurnal_khusus_baris', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('jurnal_id');
            $table->string('skpd', 150)->default('');
            $table->integer('urut')->default(0);
            $table->date('tanggal')->nullable();
            $table->string('nomor_dok', 60)->default('');
            $table->string('uraian', 255)->default('');
            $table->decimal('debet_1', 18, 2)->default(0.00);
            $table->decimal('debet_2', 18, 2)->default(0.00);
            $table->decimal('debet_3', 18, 2)->default(0.00);
            $table->decimal('debet_4', 18, 2)->default(0.00);
            $table->decimal('debet_5', 18, 2)->default(0.00);
            $table->decimal('debet_6', 18, 2)->default(0.00);
            $table->decimal('kredit_1', 18, 2)->default(0.00);
            $table->decimal('kredit_2', 18, 2)->default(0.00);
            $table->decimal('kredit_3', 18, 2)->default(0.00);
            $table->decimal('kredit_4', 18, 2)->default(0.00);
            $table->decimal('kredit_5', 18, 2)->default(0.00);
            $table->decimal('kredit_6', 18, 2)->default(0.00);
            $table->decimal('debet_7', 18, 2)->default(0.00);
            $table->decimal('debet_8', 18, 2)->default(0.00);
            $table->decimal('debet_9', 18, 2)->default(0.00);
            $table->decimal('debet_10', 18, 2)->default(0.00);
            $table->decimal('kredit_7', 18, 2)->default(0.00);
            $table->decimal('kredit_8', 18, 2)->default(0.00);
            $table->decimal('kredit_9', 18, 2)->default(0.00);
            $table->decimal('kredit_10', 18, 2)->default(0.00);
            $table->index(['jurnal_id'], 'idx_jkb_jurnal');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
