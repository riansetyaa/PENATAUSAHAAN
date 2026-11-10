<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `skp_daerah` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class SkpDaerah extends Model
{
    use HasFactory;

    protected $table = 'skp_daerah';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'nomor_skp', 'tanggal', 'jenis_pajak', 'akun_kode', 'nama_penyetor', 'alamat', 'npwpd', 'objek_pajak', 'nilai_keputusan', 'masa_pajak_dari', 'masa_pajak_akhir', 'jatuh_tempo', 'keterangan', 'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tanggal' => 'date',
        'nilai_keputusan' => 'decimal:2',
        'masa_pajak_dari' => 'date',
        'masa_pajak_akhir' => 'date',
        'jatuh_tempo' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
