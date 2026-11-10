<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder role & permission.
 *
 * Memetakan 6 nilai ENUM pada kolom `users.peran` ke role Spatie.
 * Nilai ENUM TIDAK diubah (data legacy tetap utuh) — yang ditambahkan
 * hanya salinan role agar Policy Laravel dapat melakukan otorisasi berbasis peran.
 */
class RoleSeeder extends Seeder
{
    /**
     * Daftar peran yang dikenali aplikasi.
     *
     * @var array<string, string>  nama role Spatie => nama kolom ENUM
     */
    public const ROLES = [
        'admin-dinas'   => 'Admin Dinas',
        'operator'      => 'Operator',
        'bendahara'     => 'Bendahara',
        'verifikator'   => 'Verifikator',
        'kepala-dinas'  => 'Kepala Dinas',
        'pengguna-umum' => 'Pengguna Umum',
    ];

    /**
     * Permission granular. Format: "modul:aksi".
     *
     * @var array<string, string>  nama permission => deskripsi
     */
    public const PERMISSIONS = [
        'dashboard:lihat'        => 'Melihat ringkasan APBD pada dashboard',
        'penerimaan:lihat'       => 'Melihat data penerimaan',
        'penerimaan:kelola'       => 'Menambah & mengubah data penerimaan',
        'belanja:lihat'          => 'Melihat data belanja',
        'belanja:kelola'          => 'Menambah & mengubah data belanja',
        'belanja:setujui'         => 'Menyetujui dokumen belanja (SPM/SP2D/Pengajuan TU)',
        'aklap:lihat'             => 'Melihat modul akuntansi (AKLAP)',
        'aklap:kelola'            => 'Menulis jurnal & master akun',
        'aklap:setujui'           => 'Menyetujui jurnal umum',
        'dokumen:lihat'           => 'Melihat dokumen milik sendiri',
        'dokumen:tandatangani'    => 'Menandatangani dokumen elektronik',
        'pengguna:kelola'         => 'Mengelola data pengguna',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ---- Permission ----
        foreach (self::PERMISSIONS as $name => $label) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'web', ['name' => $name]);
        }

        // ---- Role + permission per role ----
        // Operator  : Keller data harian
        // Bendahara : Keller + transaksi penerimaan
        // Verifikator: Menyetujui dokumen
        // Kepala Dinas: Semua isi modul + approval
        // Admin Dinas : Semua (termasuk kelola pengguna)
        $rolePermissions = [
            'pengguna-umum' => ['dashboard:lihat'],
            'operator'      => ['dashboard:lihat', 'penerimaan:lihat', 'belanja:lihat', 'aklap:lihat', 'dokumen:lihat'],
            'bendahara'     => ['dashboard:lihat', 'penerimaan:lihat', 'penerimaan:kelola', 'belanja:lihat', 'belanja:kelola', 'aklap:lihat', 'aklap:kelola', 'dokumen:lihat', 'dokumen:tandatangani'],
            'verifikator'   => ['dashboard:lihat', 'penerimaan:lihat', 'belanja:lihat', 'belanja:setujui', 'aklap:lihat', 'aklap:setujui', 'dokumen:lihat'],
            'kepala-dinas'  => ['dashboard:lihat', 'penerimaan:lihat', 'penerimaan:kelola', 'belanja:lihat', 'belanja:kelola', 'belanja:setujui', 'aklap:lihat', 'aklap:kelola', 'aklap:setujui', 'dokumen:lihat', 'dokumen:tandatangani'],
            'admin-dinas'   => array_keys(self::PERMISSIONS),
        ];

        foreach ($rolePermissions as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web', ['name' => $roleName]);
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ---- Sinkronkan role user yang sudah ada ----
        // Akun legacy belum pernah punya role Spatie. Sekarang semua akun
        // disinkronkan berdasarkan kolom ENUM `peran` miliknya.
        $synced = 0;
        foreach (User::query()->get() as $user) {
            $user->syncPeranRole();
            $synced++;
        }

        $this->command?->info("Role dibuat: " . count(self::ROLES) . ', permission: ' . count(self::PERMISSIONS) . ", user disinkronkan: {$synced}");
    }
}
