<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model untuk tabel `skp_daerah` (skema legacy SIM-TKD).
 *
 * @manual Relasi ditulis manual — jangan ditimpa generator.
 */
class SkpDaerah extends Model
{
    use HasFactory;

    protected $table = 'skp_daerah';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'nomor_skp', 'tanggal', 'akun_kode', 'jenis_pajak',
        'nama_penyetor', 'alamat', 'npwpd', 'objek_pajak', 'nilai_keputusan',
        'masa_pajak_dari', 'masa_pajak_akhir', 'jatuh_tempo', 'keterangan', 'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'nilai_keputusan' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * User yang membuat SKP Daerah.
     */
    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
