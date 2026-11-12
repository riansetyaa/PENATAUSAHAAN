<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * Model pengguna SIM-TKD.
 *
 * Meneruskan skema tabel `users` yang sudah ada (lihat
 * database/migrations/2026_01_01_000000_mirror_users_table.php) sehingga
 * data pengguna legacy tidak perlu dimigrasi.
 *
 * Perbedaan penting dari sistem legacy:
 *  - Token API tidak lagi memakai kolom `users.api_token` (SHA-256 manual).
 *    Sekarang memakai Sanctum Personal Access Token, yang juga menyimpan
 *    hash token di tabel `personal_access_tokens` dan mendukung expiry.
 *  - Kolom `peran` (ENUM) dipertahankan sebagai sumber kebenaran, dan
 *    disinkronkan ke role Spatie agar Policy Laravel dapat memakainya.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;

    /** Status akun. */
    public const STATUS_PENDING  = 'pending';
    public const STATUS_AKTIF    = 'aktif';
    public const STATUS_NONAKTIF = 'nonaktif';

    /**
     * Pemetaan nilai kolom `peran` (ENUM legacy) -> nama role Spatie.
     *
     * Nilai pada kolom `peran` tidak diubah agar data legacy tetap utuh;
     * yang berubah adalah sinkronisasi role Spatie.
     */
    public const PERAN_TO_ROLE = [
        'Admin Dinas'    => 'admin-dinas',
        'Operator'       => 'operator',
        'Bendahara'      => 'bendahara',
        'Verifikator'    => 'verifikator',
        'Kepala Dinas'   => 'kepala-dinas',
        'Pengguna Umum' => 'pengguna-umum',
    ];

    protected $table = 'users';

    protected $fillable = [
        'nama_lengkap',
        'nip',
        'username',
        'email',
        'password',
        'instansi',
        'kota',
        'provinsi',
        'peran',
        'status',
        'otp_hash',
        'otp_expired_at',
        'otp_terkirim_at',
        'otp_percobaan',
        'otp_verifikasi_at',
    ];

    protected $hidden = [
        'password',
        'otp_hash',
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'id'                  => 'integer',
            'otp_percobaan'       => 'integer',
            'otp_expired_at'      => 'datetime',
            'otp_terkirim_at'     => 'datetime',
            'otp_verifikasi_at'   => 'datetime',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime',
            'password'            => 'hashed',
        ];
    }

    // -----------------------------------------------------------------
    // Relasi
    // -----------------------------------------------------------------

    /** Dokumen yang dibuat user (antrean tanda tangan). */
    public function dokumen(): HasMany
    {
        return $this->hasMany(Dokumen::class);
    }

    /** Slot tanda tangan milik user. */
    public function dokumenTtd(): HasMany
    {
        return $this->hasMany(DokumenTtd::class);
    }

    // -----------------------------------------------------------------
    // Status & helper
    // -----------------------------------------------------------------

    /** Akun aktif (telah diverifikasi OTP)? */
    public function isAktif(): bool
    {
        return $this->status === self::STATUS_AKTIF;
    }

    /** Akun masih menunggu verifikasi OTP? */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Nama role Spatie yang setara dengan kolom `peran`. */
    public function roleName(): string
    {
        return self::PERAN_TO_ROLE[(string) $this->peran] ?? 'pengguna-umum';
    }

    /** Apakah user berperan admin dinas? */
    public function isAdminDinas(): bool
    {
        return $this->roleName() === 'admin-dinas';
    }

    /**
     * Sinkronkan role Spatie dengan kolom `peran`.
     *
     * Dipanggil setelah register, aktivasi, dan perubahan profil agar
     * Policy Laravel selalu konsisten dengan nilai ENUM di database.
     */
    public function syncPeranRole(): void
    {
        // Guard: saat instalasi pertama, tabel role Spatie belum terisi.
        if (Role::count() === 0) {
            return;
        }

        $this->syncRoles([$this->roleName()]);
    }
}
