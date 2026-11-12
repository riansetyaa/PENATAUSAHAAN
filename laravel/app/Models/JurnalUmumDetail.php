<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `jurnal_umum_detail` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class JurnalUmumDetail extends Model
{
    use HasFactory;

    protected $table = 'jurnal_umum_detail';

    public $timestamps = false;

    protected $fillable = [
        'jurnal_id', 'skpd', 'kode_akun', 'nama_akun', 'posisi', 'jumlah',
    ];

    protected $casts = [
        'id' => 'integer',
        'jurnal_id' => 'integer',
        'jumlah' => 'decimal:2',
        'created_at' => 'datetime',
    ];

}
