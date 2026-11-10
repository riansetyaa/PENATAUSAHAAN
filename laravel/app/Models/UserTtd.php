<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `user_ttd` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.

 */
class UserTtd extends Model
{
    use HasFactory;

    protected $table = 'user_ttd';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'gambar', 'dibuat_pada', 'diperbarui_pada',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'gambar' => 'string',
        'dibuat_pada' => 'datetime',
        'diperbarui_pada' => 'datetime',
    ];

}
