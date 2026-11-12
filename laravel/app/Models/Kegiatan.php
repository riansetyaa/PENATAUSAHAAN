<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `kegiatan` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Kegiatan extends Model
{
    use HasFactory;

    protected $table = 'kegiatan';

    public $timestamps = true;

    protected $fillable = [
        'skpd', 'nama_kegiatan', 'tahun', 'pagu', 'realisasi', 'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'tahun' => 'integer',
        'pagu' => 'decimal:2',
        'realisasi' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
