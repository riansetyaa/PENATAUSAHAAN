<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `spp_detail` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class SppDetail extends Model
{
    use HasFactory;

    protected $table = 'spp_detail';

    public $timestamps = false;

    protected $fillable = [
        'skpd', 'spp_id', 'kode_rekening', 'uraian', 'jumlah',
    ];

    protected $casts = [
        'id' => 'integer',
        'spp_id' => 'integer',
        'jumlah' => 'decimal:2',
        'created_at' => 'datetime',
    ];

}
