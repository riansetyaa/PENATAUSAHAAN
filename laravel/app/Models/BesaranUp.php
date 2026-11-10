<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `besaran_up` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class BesaranUp extends Model
{
    use HasFactory;

    protected $table = 'besaran_up';

    public $timestamps = false;

    protected $fillable = [
        'skpd', 'tahun', 'besaran_up', 'besaran_up_kkpd',
    ];

    protected $casts = [
        'id' => 'integer',
        'besaran_up' => 'decimal:2',
        'besaran_up_kkpd' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

}
