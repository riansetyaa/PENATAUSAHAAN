<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `jurnal_khusus` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class JurnalKhusus extends Model
{
    use HasFactory;

    protected $table = 'jurnal_khusus';

    public $timestamps = true;

    protected $fillable = [
        'skpd', 'user_id', 'jenis', 'tahun', 'bulan', 'kode_extra', 'skp_label',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tahun' => 'integer',
        'bulan' => 'integer',
        'kode_extra' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
