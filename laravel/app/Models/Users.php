<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Model untuk tabel `users` (skema legacy SIM-TKD).
 *
 * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.

 */
class Users extends Model
{
    use HasFactory;

    protected $table = 'users';

    public $timestamps = true;

    protected $fillable = [
        'nama_lengkap', 'nip', 'username', 'email', 'password', 'instansi', 'kota', 'provinsi', 'api_token', 'otp_hash', 'otp_expired_at', 'otp_terkirim_at', 'otp_percobaan', 'otp_verifikasi_at', 'peran', 'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'otp_expired_at' => 'datetime',
        'otp_terkirim_at' => 'datetime',
        'otp_percobaan' => 'integer',
        'otp_verifikasi_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
