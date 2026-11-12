<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `jurnal_manual` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class JurnalManual extends Model
{
    use HasFactory;

    protected $table = 'jurnal_manual';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'skpd', 'nomor_jurnal', 'nomor_dokumen', 'kode_akun', 'nama_akun', 'tanggal', 'uraian', 'jumlah',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tanggal' => 'date',
        'jumlah' => 'decimal:2',
        'created_at' => 'datetime',
    ];

}
