<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `dokumen_ttd` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.

 */
class DokumenTtd extends Model
{
    use HasFactory;

    protected $table = 'dokumen_ttd';

    public $timestamps = false;

    protected $fillable = [
        'dokumen_id', 'user_id', 'urutan', 'jabatan', 'nama', 'nip', 'status', 'signed_at', 'ip', 'user_agent',
    ];

    protected $casts = [
        'id' => 'integer',
        'dokumen_id' => 'integer',
        'user_id' => 'integer',
        'urutan' => 'integer',
        'signed_at' => 'datetime',
    ];

}
