<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `sts` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sts')) {
            return;
        }

        Schema::create('sts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('skpd', 150)->default('');
            $table->string('nomor_sts', 50);
            $table->string('nama_penyetor', 150)->default('');
            $table->date('tanggal_sts');
            $table->date('tanggal_acuan_dari')->nullable();
            $table->date('tanggal_acuan_akhir')->nullable();
            $table->string('mengetahui', 150)->default('');
            $table->string('kuasa_pengguna_anggaran', 150)->default('');
            $table->string('nama_bank', 100)->default('');
            $table->string('nomor_rekening', 50)->default('');
            $table->string('nama_rekening', 150)->default('');
            $table->string('keterangan', 255)->default('');
            $table->decimal('total', 18, 2)->default(0.00);
            $table->enum('status', ['aktif', 'dihapus'])->default('aktif');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->enum('jurnal_status', ['belum_approve', 'sudah_approve', 'ditolak'])->nullable()->default('belum_approve');
            $table->boolean('sudah_dijurnal')->default(false);
            $table->index(['status'], 'idx_status');
            $table->index(['tanggal_sts'], 'idx_tanggal');
            $table->index(['user_id'], 'idx_user');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
