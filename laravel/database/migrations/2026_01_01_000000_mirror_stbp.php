<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `stbp` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stbp')) {
            return;
        }

        Schema::create('stbp', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('skp_daerah_id')->nullable();
            $table->string('skpd', 150)->default('');
            $table->string('nomor_stbp', 50);
            $table->date('tanggal');
            $table->string('akun_kode', 50)->default('');
            $table->string('akun_nama', 200)->default('');
            $table->decimal('jumlah', 18, 2)->default(0.00);
            $table->string('uraian', 255)->default('');
            $table->enum('status', ['belum_diverifikasi', 'sudah_diverifikasi', 'sudah_diotorisasi', 'sudah_divalidasi', 'dihapus'])->default('belum_diverifikasi');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->enum('jurnal_status', ['belum_approve', 'sudah_approve', 'ditolak'])->nullable()->default('belum_approve');
            $table->index(['skp_daerah_id'], 'idx_skp');
            $table->index(['status'], 'idx_status');
            $table->index(['tanggal'], 'idx_tanggal');
            $table->index(['user_id'], 'idx_user');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
