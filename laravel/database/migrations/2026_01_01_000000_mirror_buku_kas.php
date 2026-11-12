<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `buku_kas` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buku_kas')) {
            return;
        }

        Schema::create('buku_kas', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->default('');
            $table->unsignedInteger('user_id')->nullable();
            $table->date('tanggal')->nullable();
            $table->enum('jenis', ['penerimaan', 'pengeluaran'])->default('penerimaan');
            $table->string('no_bukti', 80)->default('');
            $table->string('kode_rekening', 50)->default('');
            $table->string('uraian', 255)->default('');
            $table->decimal('jumlah', 18, 2)->default(0.00);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['skpd'], 'idx_bk_skpd');
            $table->index(['tanggal'], 'idx_bk_tanggal');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
