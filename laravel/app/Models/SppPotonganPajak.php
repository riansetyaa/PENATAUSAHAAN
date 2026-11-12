<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `spp_potongan_pajak` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class SppPotonganPajak extends Model
{
    use HasFactory;

    protected $table = 'spp_potongan_pajak';

    public $timestamps = false;

    protected $fillable = [
        'skpd', 'spp_id', 'jenis', 'nama', 'nilai_persen', 'nilai', 'id_billing', 'tgl_billing', 'ntpn', 'tgl_ntpn',
    ];

    protected $casts = [
        'id' => 'integer',
        'spp_id' => 'integer',
        'nilai_persen' => 'decimal:2',
        'nilai' => 'decimal:2',
        'tgl_billing' => 'date',
        'tgl_ntpn' => 'date',
        'created_at' => 'datetime',
    ];

}
