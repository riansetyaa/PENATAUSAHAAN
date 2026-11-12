<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `spp_potongan_pajak` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spp_potongan_pajak')) {
            return;
        }

        Schema::create('spp_potongan_pajak', function (Blueprint $table) {
            $table->increments('id');
            $table->string('skpd', 150)->nullable();
            $table->unsignedInteger('spp_id')->nullable();
            $table->enum('jenis', ['potongan', 'pajak'])->nullable()->default('potongan');
            $table->string('nama', 150)->nullable();
            $table->decimal('nilai_persen', 5, 2)->nullable()->default(0.00);
            $table->decimal('nilai', 18, 2)->nullable()->default(0.00);
            $table->string('id_billing', 100)->nullable();
            $table->date('tgl_billing')->nullable();
            $table->string('ntpn', 100)->nullable();
            $table->date('tgl_ntpn')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->index(['spp_id'], 'idx_pp_spp');
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};
