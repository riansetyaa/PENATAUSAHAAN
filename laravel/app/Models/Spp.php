<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `spp` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Spp extends Model
{
    use HasFactory;

    protected $table = 'spp';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'nomor_spp', 'tanggal', 'jenis_spp', 'spd_id', 'rekanan_id', 'lpj_id', 'pengajuan_tu_id', 'keperluan', 'jumlah', 'total_potongan', 'total_pajak', 'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tanggal' => 'date',
        'spd_id' => 'integer',
        'rekanan_id' => 'integer',
        'lpj_id' => 'integer',
        'pengajuan_tu_id' => 'integer',
        'jumlah' => 'decimal:2',
        'total_potongan' => 'decimal:2',
        'total_pajak' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
