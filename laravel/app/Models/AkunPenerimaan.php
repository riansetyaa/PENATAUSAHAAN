<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model untuk tabel `akun_penerimaan` (skema legacy SIM-TKD).
 *
 * @manual Relasi ditulis manual — jangan ditimpa generator.
 */
class AkunPenerimaan extends Model
{
    use HasFactory;

    protected $table = 'akun_penerimaan';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'kode_akun', 'nama_akun', 'metode_input', 'status_aktif',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status_aktif' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * User yang membuat akun penerimaan.
     */
    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
