<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `jurnal_khusus_baris` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class JurnalKhususBaris extends Model
{
    use HasFactory;

    protected $table = 'jurnal_khusus_baris';

    public $timestamps = false;

    protected $fillable = [
        'jurnal_id', 'skpd', 'urut', 'tanggal', 'nomor_dok', 'uraian', 'debet_1', 'debet_2', 'debet_3', 'debet_4', 'debet_5', 'debet_6', 'kredit_1', 'kredit_2', 'kredit_3', 'kredit_4', 'kredit_5', 'kredit_6', 'debet_7', 'debet_8', 'debet_9', 'debet_10', 'kredit_7', 'kredit_8', 'kredit_9', 'kredit_10',
    ];

    protected $casts = [
        'id' => 'integer',
        'jurnal_id' => 'integer',
        'urut' => 'integer',
        'tanggal' => 'date',
        'debet_1' => 'decimal:2',
        'debet_2' => 'decimal:2',
        'debet_3' => 'decimal:2',
        'debet_4' => 'decimal:2',
        'debet_5' => 'decimal:2',
        'debet_6' => 'decimal:2',
        'kredit_1' => 'decimal:2',
        'kredit_2' => 'decimal:2',
        'kredit_3' => 'decimal:2',
        'kredit_4' => 'decimal:2',
        'kredit_5' => 'decimal:2',
        'kredit_6' => 'decimal:2',
        'debet_7' => 'decimal:2',
        'debet_8' => 'decimal:2',
        'debet_9' => 'decimal:2',
        'debet_10' => 'decimal:2',
        'kredit_7' => 'decimal:2',
        'kredit_8' => 'decimal:2',
        'kredit_9' => 'decimal:2',
        'kredit_10' => 'decimal:2',
    ];

}
