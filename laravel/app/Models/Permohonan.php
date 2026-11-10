<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model untuk tabel `permohonan` (skema legacy SIM-TKD).
 *
 * @manual Relasi & accessor ditulis manual — jangan ditimpa generator.
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

    /**
     * User yang membuat permohonan (dipakai sebagai kolom `dibuat_oleh`).
     */
    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
