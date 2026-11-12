<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `jurnal_umum` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jurnal_umum')) {
            return;
        }

        Schema::create('jurnal_umum', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->default('');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('nomor_jurnal', 60)->default('');
            $table->date('tanggal')->nullable();
            $table->string('uraian', 255)->default('');
            $table->enum('sumber_tipe', ['manual', 'sts', 'sp2d'])->default('manual');
            $table->unsignedInteger('sumber_id')->nullable();
            $table->enum('status', ['draft', 'sudah_approve', 'ditolak'])->default('draft');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->index(['skpd'], 'idx_ju_skpd');
            $table->index(['status'], 'idx_ju_status');
            $table->index(['sumber_tipe', 'sumber_id'], 'idx_ju_sumber');
            $table->index(['tanggal'], 'idx_ju_tanggal');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
