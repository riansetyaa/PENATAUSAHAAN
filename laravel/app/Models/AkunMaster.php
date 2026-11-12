<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `akun_master` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class AkunMaster extends Model
{
    use HasFactory;

    protected $table = 'akun_master';

    public $timestamps = false;

    protected $fillable = [
        'skpd', 'kode_akun', 'nama_akun', 'tipe', 'saldo_normal', 'aktif',
    ];

    protected $casts = [
        'id' => 'integer',
        'aktif' => 'boolean',
        'created_at' => 'datetime',
    ];

}
