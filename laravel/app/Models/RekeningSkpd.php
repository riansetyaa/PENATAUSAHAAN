<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `rekening_skpd` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class RekeningSkpd extends Model
{
    use HasFactory;

    protected $table = 'rekening_skpd';

    public $timestamps = true;

    protected $fillable = [
        'skpd', 'user_id', 'bank', 'nama_pemilik', 'nomor_rekening', 'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
