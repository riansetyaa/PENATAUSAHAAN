<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `npd` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Npd extends Model
{
    use HasFactory;

    protected $table = 'npd';

    public $timestamps = true;

    protected $fillable = [
        'skpd', 'user_id', 'nomor_npd', 'tanggal', 'metode', 'keterangan', 'kegiatan', 'sub_kegiatan', 'jumlah', 'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tanggal' => 'date',
        'jumlah' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
