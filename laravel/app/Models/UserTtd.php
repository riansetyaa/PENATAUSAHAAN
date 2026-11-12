<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `user_ttd` (gambar tanda tangan milik user).
 *
 * @manual Relasi ditulis manual — jangan ditimpa generator.
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
        'dibuat_pada' => 'datetime',
        'diperbarui_pada' => 'datetime',
    ];
}
