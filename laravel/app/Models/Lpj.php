<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `lpj` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Lpj extends Model
{
    use HasFactory;

    protected $table = 'lpj';

    public $timestamps = false;

    protected $fillable = [
        'skpd', 'nomor_lpj', 'tanggal', 'uraian', 'jumlah',
    ];

    protected $casts = [
        'id' => 'integer',
        'tanggal' => 'date',
        'jumlah' => 'decimal:2',
        'created_at' => 'datetime',
    ];

}
