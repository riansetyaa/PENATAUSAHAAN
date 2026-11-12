<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `dokumen` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dokumen')) {
            return;
        }

        Schema::create('dokumen', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('skpd', 150)->nullable();
            $table->string('jenis', 50)->nullable();
            $table->unsignedInteger('ref_id')->nullable();
            $table->string('nomor', 100)->nullable();
            $table->string('judul', 255)->nullable();
            $table->date('tanggal')->nullable();
            $table->longText('konten_html')->nullable();
            $table->longText('konten_html_signed')->nullable();
            $table->char('hash_original', 64)->nullable();
            $table->char('hash_signed', 64)->nullable();
            $table->string('kode_verifikasi', 20)->nullable();
            $table->enum('status', ['menunggu_ttd', 'ditandatangani'])->nullable()->default('menunggu_ttd');
            $table->dateTime('signed_at')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->unique(['kode_verifikasi'], 'uq_dokumen_kode');
            $table->index(['skpd'], 'idx_dokumen_skpd');
            $table->index(['user_id'], 'idx_dokumen_user');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
