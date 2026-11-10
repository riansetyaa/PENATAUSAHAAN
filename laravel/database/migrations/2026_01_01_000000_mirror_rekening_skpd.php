<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `rekening_skpd` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rekening_skpd')) {
            return;
        }

        Schema::create('rekening_skpd', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('bank', 100)->nullable();
            $table->string('nama_pemilik', 150)->nullable();
            $table->string('nomor_rekening', 50)->nullable();
            $table->enum('status', ['permohonan', 'pengajuan', 'pembuatan', 'aktif', 'ditolak'])->nullable()->default('permohonan');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->index(['skpd'], 'idx_rek_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
