<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `jurnal_umum_detail` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jurnal_umum_detail')) {
            return;
        }

        Schema::create('jurnal_umum_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('jurnal_id');
            $table->string('skpd', 150)->default('');
            $table->string('kode_akun', 50)->default('');
            $table->string('nama_akun', 200)->default('');
            $table->enum('posisi', ['debet', 'kredit']);
            $table->decimal('jumlah', 18, 2)->default(0.00);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['skpd', 'kode_akun'], 'idx_jud_akun');
            $table->index(['jurnal_id'], 'idx_jud_jurnal');
            $table->index(['posisi'], 'idx_jud_posisi');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
