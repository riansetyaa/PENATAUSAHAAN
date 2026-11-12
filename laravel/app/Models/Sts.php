<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `sts` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Sts extends Model
{
    use HasFactory;

    protected $table = 'sts';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'nomor_sts', 'nama_penyetor', 'tanggal_sts', 'tanggal_acuan_dari', 'tanggal_acuan_akhir', 'mengetahui', 'kuasa_pengguna_anggaran', 'nama_bank', 'nomor_rekening', 'nama_rekening', 'keterangan', 'total', 'status', 'jurnal_status', 'sudah_dijurnal',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tanggal_sts' => 'date',
        'tanggal_acuan_dari' => 'date',
        'tanggal_acuan_akhir' => 'date',
        'total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'sudah_dijurnal' => 'boolean',
    ];

}
