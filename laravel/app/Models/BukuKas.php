<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `buku_kas` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class BukuKas extends Model
{
    use HasFactory;

    protected $table = 'buku_kas';

    public $timestamps = false;

    protected $fillable = [
        'skpd', 'user_id', 'tanggal', 'jenis', 'no_bukti', 'kode_rekening', 'uraian', 'jumlah',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tanggal' => 'date',
        'jumlah' => 'decimal:2',
        'created_at' => 'datetime',
    ];

}
