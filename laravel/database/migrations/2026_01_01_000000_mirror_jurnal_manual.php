<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `jurnal_manual` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jurnal_manual')) {
            return;
        }

        Schema::create('jurnal_manual', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('skpd', 150)->default('');
            $table->string('nomor_jurnal', 60)->default('');
            $table->string('nomor_dokumen', 100)->default('');
            $table->string('kode_akun', 50)->default('');
            $table->string('nama_akun', 200)->default('');
            $table->date('tanggal')->nullable();
            $table->string('uraian', 255)->default('');
            $table->decimal('jumlah', 15, 2)->default(0.00);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['skpd'], 'idx_skpd');
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
