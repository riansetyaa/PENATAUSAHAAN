<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `skp_daerah` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skp_daerah')) {
            return;
        }

        Schema::create('skp_daerah', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('skpd', 150)->default('');
            $table->string('nomor_skp', 50);
            $table->date('tanggal');
            $table->string('jenis_pajak', 120)->default('');
            $table->string('akun_kode', 50)->default('');
            $table->string('nama_penyetor', 150)->default('');
            $table->string('alamat', 255)->default('');
            $table->string('npwpd', 50)->default('');
            $table->string('objek_pajak', 255)->default('');
            $table->decimal('nilai_keputusan', 18, 2)->default(0.00);
            $table->date('masa_pajak_dari')->nullable();
            $table->date('masa_pajak_akhir')->nullable();
            $table->date('jatuh_tempo')->nullable();
            $table->string('keterangan', 255)->default('');
            $table->enum('status', ['aktif', 'terpakai', 'dihapus'])->default('aktif');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->index(['nomor_skp'], 'idx_nomor');
            $table->index(['status'], 'idx_status');
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
