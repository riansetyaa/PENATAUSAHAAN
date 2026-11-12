<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `sp2d` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Sp2d extends Model
{
    use HasFactory;

    protected $table = 'sp2d';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'nomor_sp2d', 'tanggal', 'spm_id', 'rekening', 'jumlah', 'status', 'jurnal_status', 'sudah_dijurnal',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tanggal' => 'date',
        'spm_id' => 'integer',
        'jumlah' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'sudah_dijurnal' => 'boolean',
    ];

}
