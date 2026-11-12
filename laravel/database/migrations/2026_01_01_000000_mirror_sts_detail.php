<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `sts_detail` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sts_detail')) {
            return;
        }

        Schema::create('sts_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('sts_id');
            $table->unsignedInteger('stbp_id')->default(0);
            $table->string('nomor_stbp', 50)->default('');
            $table->date('tanggal')->nullable();
            $table->string('akun_kode', 50)->default('');
            $table->string('akun_nama', 200)->default('');
            $table->decimal('jumlah', 18, 2)->default(0.00);
            $table->string('uraian', 255)->default('');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['sts_id'], 'idx_sts');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
