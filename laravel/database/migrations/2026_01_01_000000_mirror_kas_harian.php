<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `kas_harian` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kas_harian')) {
            return;
        }

        Schema::create('kas_harian', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->default('');
            $table->unsignedInteger('user_id')->nullable();
            $table->date('tanggal')->nullable();
            $table->string('hari', 12)->default('');
            $table->string('periode', 20)->default('');
            $table->decimal('posisi_h1', 18, 2)->default(0.00);
            $table->decimal('total_penerimaan', 18, 2)->default(0.00);
            $table->decimal('total_pengeluaran', 18, 2)->default(0.00);
            $table->decimal('posisi_h', 18, 2)->default(0.00);
            $table->string('bank1_nama', 80)->default('Saldo di Bank 1');
            $table->decimal('bank1_saldo', 18, 2)->default(0.00);
            $table->string('bank2_nama', 80)->default('Saldo di Bank 2');
            $table->decimal('bank2_saldo', 18, 2)->default(0.00);
            $table->string('catatan', 255)->default('');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->unique(['skpd', 'tanggal'], 'uq_kh_skpd');
            $table->index(['skpd'], 'idx_kh_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
