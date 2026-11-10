<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `stbp` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Stbp extends Model
{
    use HasFactory;

    protected $table = 'stbp';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skp_daerah_id', 'skpd', 'nomor_stbp', 'tanggal', 'akun_kode', 'akun_nama', 'jumlah', 'uraian', 'status', 'jurnal_status',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'skp_daerah_id' => 'integer',
        'tanggal' => 'date',
        'jumlah' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
