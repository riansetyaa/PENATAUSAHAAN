<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model untuk tabel `dokumen_ttd` (slot tanda tangan dokumen).
 *
 * @manual Relasi ditulis manual — jangan ditimpa generator.
 */
class DokumenTtd extends Model
{
    use HasFactory;

    protected $table = 'dokumen_ttd';

    public $timestamps = false;

    protected $fillable = [
        'dokumen_id', 'user_id', 'urutan', 'jabatan', 'nama', 'nip',
        'status', 'signed_at', 'ip', 'user_agent',
    ];

    protected $casts = [
        'id' => 'integer',
        'dokumen_id' => 'integer',
        'user_id' => 'integer',
        'urutan' => 'integer',
        'signed_at' => 'datetime',
    ];

    /**
     * Dokumen yang memiliki slot tanda tangan ini.
     */
    public function dokumen(): BelongsTo
    {
        return $this->belongsTo(Dokumen::class, 'dokumen_id');
    }

    /**
     * Penandatangan yang sesuai dengan slot ini (bisa NULL untuk pihak
     * eksternal).
     */
    public function penandatangan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
