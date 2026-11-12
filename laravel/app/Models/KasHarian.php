<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `kas_harian` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class KasHarian extends Model
{
    use HasFactory;

    protected $table = 'kas_harian';

    public $timestamps = true;

    protected $fillable = [
        'skpd', 'user_id', 'tanggal', 'hari', 'periode', 'posisi_h1', 'total_penerimaan', 'total_pengeluaran', 'posisi_h', 'bank1_nama', 'bank1_saldo', 'bank2_nama', 'bank2_saldo', 'catatan',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tanggal' => 'date',
        'posisi_h1' => 'decimal:2',
        'total_penerimaan' => 'decimal:2',
        'total_pengeluaran' => 'decimal:2',
        'posisi_h' => 'decimal:2',
        'bank1_saldo' => 'decimal:2',
        'bank2_saldo' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
