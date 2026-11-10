<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `kegiatan` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kegiatan')) {
            return;
        }

        Schema::create('kegiatan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->nullable();
            $table->string('nama_kegiatan', 200);
            $table->smallInteger('tahun');
            $table->decimal('pagu', 18, 2)->default(0.00);
            $table->decimal('realisasi', 18, 2)->default(0.00);
            $table->enum('status', ['berjalan', 'selesai', 'batal'])->nullable()->default('berjalan');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->index(['skpd'], 'idx_kegiatan_skpd');
            $table->index(['tahun'], 'idx_tahun');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
