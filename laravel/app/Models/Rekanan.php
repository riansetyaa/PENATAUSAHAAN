<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `rekanan` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Rekanan extends Model
{
    use HasFactory;

    protected $table = 'rekanan';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'nama_rekanan', 'npwp', 'alamat', 'bank', 'nomor_rekening', 'nama_rekening', 'jenis',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
