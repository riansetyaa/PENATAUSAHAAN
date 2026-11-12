<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `anggaran_lra` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class AnggaranLra extends Model
{
    use HasFactory;

    protected $table = 'anggaran_lra';

    public $timestamps = true;

    protected $fillable = [
        'skpd', 'tahun', 'kode_akun', 'nama_akun', 'anggaran',
    ];

    protected $casts = [
        'id' => 'integer',
        'anggaran' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
