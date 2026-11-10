<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `akun_penerimaan` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class AkunPenerimaan extends Model
{
    use HasFactory;

    protected $table = 'akun_penerimaan';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'kode_akun', 'nama_akun', 'metode_input', 'status_aktif',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status_aktif' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
