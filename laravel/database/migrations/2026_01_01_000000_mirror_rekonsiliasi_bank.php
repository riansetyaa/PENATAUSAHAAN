<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `rekonsiliasi_bank` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rekonsiliasi_bank')) {
            return;
        }

        Schema::create('rekonsiliasi_bank', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->default('');
            $table->unsignedInteger('user_id')->nullable();
            $table->smallInteger('tahun')->default(0);
            $table->unsignedTinyInteger('bulan')->default(0);
            $table->string('periode', 20)->default('');
            $table->date('tgl_ttd')->nullable();
            $table->decimal('saldo_buku', 18, 2)->default(0.00);
            $table->decimal('saldo_bank', 18, 2)->default(0.00);
            $table->string('catatan', 255)->default('');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->unique(['skpd', 'tahun', 'bulan'], 'uq_rb_skpd');
            $table->index(['skpd'], 'idx_rb_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
