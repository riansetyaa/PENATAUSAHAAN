<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `register_ppkd` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('register_ppkd')) {
            return;
        }

        Schema::create('register_ppkd', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->default('');
            $table->unsignedInteger('buku_kas_id');
            $table->date('spp_tgl')->nullable();
            $table->string('spp_no', 64)->default('');
            $table->date('spm_tgl')->nullable();
            $table->string('spm_no', 64)->default('');
            $table->date('sp2d_tgl')->nullable();
            $table->string('sp2d_no', 64)->default('');
            $table->string('ket', 120)->default('');
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->unique(['skpd', 'buku_kas_id'], 'uq_reg_bk');
            $table->index(['skpd'], 'idx_reg_skpd');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
