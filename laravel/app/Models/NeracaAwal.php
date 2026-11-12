<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `neraca_awal` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class NeracaAwal extends Model
{
    use HasFactory;

    protected $table = 'neraca_awal';

    public $timestamps = true;

    protected $fillable = [
        'skpd', 'tahun', 'kode_akun', 'nama_akun', 'saldo', 'jenis',
    ];

    protected $casts = [
        'id' => 'integer',
        'saldo' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
