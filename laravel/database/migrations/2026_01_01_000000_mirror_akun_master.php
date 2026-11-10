<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `akun_master` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('akun_master')) {
            return;
        }

        Schema::create('akun_master', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->default('');
            $table->string('kode_akun', 50);
            $table->string('nama_akun', 200);
            $table->enum('tipe', ['aset', 'liabilitas', 'ekuitas', 'pendapatan', 'belanja'])->default('aset');
            $table->enum('saldo_normal', ['debet', 'kredit'])->default('debet');
            $table->boolean('aktif')->default(true);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->unique(['skpd', 'kode_akun'], 'uq_akun_skpd');
            $table->index(['kode_akun'], 'idx_akun_kode');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
