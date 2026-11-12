<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `jurnal_umum` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class JurnalUmum extends Model
{
    use HasFactory;

    protected $table = 'jurnal_umum';

    public $timestamps = true;

    protected $fillable = [
        'skpd', 'user_id', 'nomor_jurnal', 'tanggal', 'uraian', 'sumber_tipe', 'sumber_id', 'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tanggal' => 'date',
        'sumber_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
