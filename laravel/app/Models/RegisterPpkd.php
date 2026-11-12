<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `register_ppkd` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class RegisterPpkd extends Model
{
    use HasFactory;

    protected $table = 'register_ppkd';

    public $timestamps = false;

    protected $fillable = [
        'skpd', 'buku_kas_id', 'spp_tgl', 'spp_no', 'spm_tgl', 'spm_no', 'sp2d_tgl', 'sp2d_no', 'ket', 'user_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'buku_kas_id' => 'integer',
        'spp_tgl' => 'date',
        'spm_tgl' => 'date',
        'sp2d_tgl' => 'date',
        'user_id' => 'integer',
        'updated_at' => 'datetime',
    ];

}
