<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `spp` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spp')) {
            return;
        }

        Schema::create('spp', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('skpd', 150)->nullable();
            $table->string('nomor_spp', 50);
            $table->date('tanggal')->nullable();
            $table->string('jenis_spp', 50)->nullable();
            $table->unsignedInteger('spd_id')->nullable();
            $table->unsignedInteger('rekanan_id')->nullable();
            $table->unsignedInteger('lpj_id')->nullable();
            $table->unsignedInteger('pengajuan_tu_id')->nullable();
            $table->string('keperluan', 255)->nullable();
            $table->decimal('jumlah', 18, 2)->nullable()->default(0.00);
            $table->decimal('total_potongan', 18, 2)->nullable()->default(0.00);
            $table->decimal('total_pajak', 18, 2)->nullable()->default(0.00);
            $table->enum('status', ['belum_diverifikasi', 'sudah_diverifikasi', 'ditolak'])->nullable()->default('belum_diverifikasi');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->index(['skpd'], 'idx_spp_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
