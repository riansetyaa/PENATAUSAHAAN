<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `stbp_pembayaran` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.

 */
class StbpPembayaran extends Model
{
    use HasFactory;

    protected $table = 'stbp_pembayaran';

    public $timestamps = false;

    protected $fillable = [
        'stbp_id', 'metode_penyetoran', 'nama_penyetor', 'nama_bank', 'nomor_rekening',
    ];

    protected $casts = [
        'id' => 'integer',
        'stbp_id' => 'integer',
        'created_at' => 'datetime',
    ];

}
