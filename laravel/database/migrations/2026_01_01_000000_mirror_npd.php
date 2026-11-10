<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `npd` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('npd')) {
            return;
        }

        Schema::create('npd', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('nomor_npd', 50);
            $table->date('tanggal')->nullable();
            $table->enum('metode', ['panjar', 'non_panjar'])->nullable()->default('panjar');
            $table->string('keterangan', 255)->nullable();
            $table->string('kegiatan', 200)->nullable();
            $table->string('sub_kegiatan', 200)->nullable();
            $table->decimal('jumlah', 18, 2)->nullable()->default(0.00);
            $table->enum('status', ['diajukan', 'divalidasi_pa', 'divalidasi_bp', 'ditolak'])->nullable()->default('diajukan');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->index(['skpd'], 'idx_npd_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
