<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `sp2d` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sp2d')) {
            return;
        }

        Schema::create('sp2d', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('skpd', 150)->nullable();
            $table->string('nomor_sp2d', 50);
            $table->date('tanggal')->nullable();
            $table->unsignedInteger('spm_id')->nullable();
            $table->string('rekening', 100)->nullable();
            $table->decimal('jumlah', 18, 2)->nullable()->default(0.00);
            $table->enum('status', ['belum_diverifikasi', 'sudah_diverifikasi', 'sudah_dicairkan', 'ditolak'])->nullable()->default('belum_diverifikasi');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->enum('jurnal_status', ['belum_approve', 'sudah_approve', 'ditolak'])->nullable()->default('belum_approve');
            $table->boolean('sudah_dijurnal')->default(false);
            $table->index(['skpd'], 'idx_sp2d_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
