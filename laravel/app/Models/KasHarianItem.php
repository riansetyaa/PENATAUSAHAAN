<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `kas_harian_item` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class KasHarianItem extends Model
{
    use HasFactory;

    protected $table = 'kas_harian_item';

    public $timestamps = false;

    protected $fillable = [
        'kas_harian_id', 'skpd', 'urut', 'no_sp2d', 'no_sts', 'no_lain', 'uraian', 'penerimaan', 'pengeluaran',
    ];

    protected $casts = [
        'id' => 'integer',
        'kas_harian_id' => 'integer',
        'urut' => 'integer',
        'penerimaan' => 'decimal:2',
        'pengeluaran' => 'decimal:2',
    ];

}
