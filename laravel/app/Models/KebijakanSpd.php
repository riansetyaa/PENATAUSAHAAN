<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `kebijakan_spd` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class KebijakanSpd extends Model
{
    use HasFactory;

    protected $table = 'kebijakan_spd';

    public $timestamps = false;

    protected $fillable = [
        'skpd', 'jenis_penerbitan', 'jenis_periode', 'tanggal', 'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'tanggal' => 'date',
        'created_at' => 'datetime',
    ];

}
