<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `stbp_pendapatan` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.

 */
class StbpPendapatan extends Model
{
    use HasFactory;

    protected $table = 'stbp_pendapatan';

    public $timestamps = false;

    protected $fillable = [
        'stbp_id', 'akun_kode', 'akun_nama', 'rekening_bank', 'rekening_nama', 'rekening_nomor', 'nominal',
    ];

    protected $casts = [
        'id' => 'integer',
        'stbp_id' => 'integer',
        'nominal' => 'decimal:2',
        'created_at' => 'datetime',
    ];

}
