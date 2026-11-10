<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `dokumen` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.
 *
 * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter
 * per instansi/SKPD lewat global scope yang dipasang App\Models\Model.
 */
class Dokumen extends Model
{
    use HasFactory;

    protected $table = 'dokumen';

    public $timestamps = true;

    protected $fillable = [
        'user_id', 'skpd', 'jenis', 'ref_id', 'nomor', 'judul', 'tanggal', 'konten_html', 'konten_html_signed', 'hash_original', 'hash_signed', 'kode_verifikasi', 'status', 'signed_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'ref_id' => 'integer',
        'tanggal' => 'date',
        'konten_html' => 'string',
        'konten_html_signed' => 'string',
        'signed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
