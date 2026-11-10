<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `permohonan` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Permohonan extends Model
{
    use HasFactory;

    protected $table = 'permohonan';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'bank', 'nama_rekening', 'nomor_rekening', 'status_terbit', 'status_disetujui', 'status_aktif',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status_terbit' => 'boolean',
        'status_disetujui' => 'boolean',
        'status_aktif' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
