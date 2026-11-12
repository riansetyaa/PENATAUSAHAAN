<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `sts_detail` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.

 */
class StsDetail extends Model
{
    use HasFactory;

    protected $table = 'sts_detail';

    public $timestamps = false;

    protected $fillable = [
        'sts_id', 'stbp_id', 'nomor_stbp', 'tanggal', 'akun_kode', 'akun_nama', 'jumlah', 'uraian',
    ];

    protected $casts = [
        'id' => 'integer',
        'sts_id' => 'integer',
        'stbp_id' => 'integer',
        'tanggal' => 'date',
        'jumlah' => 'decimal:2',
        'created_at' => 'datetime',
    ];

}
