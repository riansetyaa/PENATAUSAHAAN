<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `jurnal_khusus` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jurnal_khusus')) {
            return;
        }

        Schema::create('jurnal_khusus', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->default('');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('jenis', 30)->default('');
            $table->smallInteger('tahun')->default(0);
            $table->unsignedTinyInteger('bulan')->default(0);
            $table->text('kode_extra')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->string('skp_label', 80)->default('');
            $table->unique(['skpd', 'jenis', 'tahun', 'bulan'], 'uniq_jk');
            $table->index(['skpd'], 'idx_jk_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
