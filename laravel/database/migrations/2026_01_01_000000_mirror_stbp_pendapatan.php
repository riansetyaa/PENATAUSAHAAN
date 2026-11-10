<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `stbp_pendapatan` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stbp_pendapatan')) {
            return;
        }

        Schema::create('stbp_pendapatan', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('stbp_id');
            $table->string('akun_kode', 50)->default('');
            $table->string('akun_nama', 200)->default('');
            $table->string('rekening_bank', 100)->default('');
            $table->string('rekening_nama', 150)->default('');
            $table->string('rekening_nomor', 50)->default('');
            $table->decimal('nominal', 18, 2)->default(0.00);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['stbp_id'], 'idx_stbp');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
