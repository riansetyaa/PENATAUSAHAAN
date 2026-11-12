<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `rekonsiliasi_bank` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class RekonsiliasiBank extends Model
{
    use HasFactory;

    protected $table = 'rekonsiliasi_bank';

    public $timestamps = true;

    protected $fillable = [
        'skpd', 'user_id', 'tahun', 'bulan', 'periode', 'tgl_ttd', 'saldo_buku', 'saldo_bank', 'catatan',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tahun' => 'integer',
        'bulan' => 'integer',
        'tgl_ttd' => 'date',
        'saldo_buku' => 'decimal:2',
        'saldo_bank' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
