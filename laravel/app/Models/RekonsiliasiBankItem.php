<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `rekonsiliasi_bank_item` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class RekonsiliasiBankItem extends Model
{
    use HasFactory;

    protected $table = 'rekonsiliasi_bank_item';

    public $timestamps = false;

    protected $fillable = [
        'rekonsiliasi_id', 'skpd', 'bagian', 'urut', 'uraian', 'nilai',
    ];

    protected $casts = [
        'id' => 'integer',
        'rekonsiliasi_id' => 'integer',
        'urut' => 'integer',
        'nilai' => 'decimal:2',
    ];

}
