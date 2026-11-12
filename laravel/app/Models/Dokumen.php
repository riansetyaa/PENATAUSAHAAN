<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model untuk tabel `dokumen` (skema legacy SIM-TKD).
 *
 * @manual Relasi ditulis manual — jangan ditimpa generator.
 */
class Dokumen extends Model
{
    use HasFactory;

    protected $table = 'dokumen';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'jenis', 'ref_id', 'nomor', 'judul', 'tanggal',
        'konten_html', 'konten_html_signed', 'hash_original', 'hash_signed',
        'kode_verifikasi', 'status', 'signed_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'ref_id' => 'integer',
        'signed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Slot tanda tangan dokumen ini.
     */
    public function slotTtd(): HasMany
    {
        return $this->hasMany(DokumenTtd::class, 'dokumen_id');
    }

    /**
     * Pembuat dokumen.
     */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
