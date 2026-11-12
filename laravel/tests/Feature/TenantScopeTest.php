<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AkunMaster;
use App\Models\Sts;
use App\Support\Concerns\HasSkpdScope;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tes multi-tenant (filter per instansi/SKPD).
 *
 * Menggantikan `requireInstansi()` + filter `WHERE skpd = ?` manual pada
 * sistem legacy. Yang diuji di sini adalah global scope: setiap query pada
 * tabel yang punya kolom `skpd` HARUS otomatis dibatasi ke instansi user
 * yang sedang login.
 */
class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private function buatAkun(string $instansi): \App\Models\User
    {
        return \App\Models\User::create([
            'nama_lengkap' => 'User ' . $instansi,
            'username'     => 'u' . md5($instansi),
            'email'        => md5($instansi) . '@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => $instansi,
            'peran'        => 'Bendahara',
            'status'       => 'aktif',
        ]);
    }

    public function test_tabel_tanpa_kolom_skpd_tidak_di_scope(): void
    {
        // Model `User` TIDAK mewarisi App\Models\Model, jadi global scope
        // multi-tenant tidak pernah dipasang padanya. Tabel `users` memang
        // tidak punya kolom `skpd`.
        $this->assertFalse(
            is_subclass_of(\App\Models\User::class, \App\Models\Model::class),
            'Model User seharusnya tidak mewarisi base Model yang mememasang scope tenant'
        );

        // Tapi tabel domain yang punya kolom skpd MEMILIKI scope.
        $this->assertTrue(
            (new AkunMaster())->hasTenantColumn(),
            'Tabel akun_master seharusnya punya kolom tenant'
        );
    }

    public function test_query_terbatas_pada_skpd_user_login(): void
    {
        $userA = $this->buatAkun('Dinas A');
        $userB = $this->buatAkun('Dinas B');

        AkunMaster::withoutGlobalScope(HasSkpdScope::class)->delete();

        AkunMaster::withoutGlobalScope(HasSkpdScope::class)->create([
            'skpd'        => 'Dinas A',
            'kode_akun'   => '1.1.01',
            'nama_akun'   => 'Kas di Bendahara A',
            'tipe'        => 'aset',
            'saldo_normal' => 'debet',
        ]);
        AkunMaster::withoutGlobalScope(HasSkpdScope::class)->create([
            'skpd'        => 'Dinas B',
            'kode_akun'   => '1.1.01',
            'nama_akun'   => 'Kas di Bendahara B',
            'tipe'        => 'aset',
            'saldo_normal' => 'debet',
        ]);

        // Login sebagai user A -> hanya akun milik Dinas A yang terlihat.
        $this->actingAs($userA);

        $this->assertSame('Dinas A', Tenant::skpd());
        $this->assertSame(1, AkunMaster::count());
        $this->assertSame('Dinas A', AkunMaster::first()->skpd);

        // Ganti ke user B -> hanya akun milik Dinas B.
        $this->actingAs($userB);

        $this->assertSame('Dinas B', Tenant::skpd());
        $this->assertSame(1, AkunMaster::count());
        $this->assertSame('Dinas B', AkunMaster::first()->skpd);
    }

    public function test_scope_dapat_dimatikan_untuk_operations_konsolidator(): void
    {
        $user = $this->buatAkun('Dinas A');

        AkunMaster::withoutGlobalScope(HasSkpdScope::class)->create([
            'skpd'         => 'Dinas A',
            'kode_akun'    => '1.1.02',
            'nama_akun'    => 'Kas A',
            'tipe'         => 'aset',
            'saldo_normal' => 'debet',
        ]);
        AkunMaster::withoutGlobalScope(HasSkpdScope::class)->create([
            'skpd'         => 'Dinas B',
            'kode_akun'    => '1.1.02',
            'nama_akun'    => 'Kas B',
            'tipe'         => 'aset',
            'saldo_normal' => 'debet',
        ]);

        $this->actingAs($user);

        $this->assertSame(1, AkunMaster::count());
        $this->assertSame(2, AkunMaster::withoutGlobalScope(HasSkpdScope::class)->count());
    }

    public function test_user_tanpa_instansi_tidak_melihat_data_manapun(): void
    {
        // Fail-closed: tanpa instansi, scope menyaring SEMUA baris.
        $user = $this->buatAkun('Dinas A');

        AkunMaster::withoutGlobalScope(HasSkpdScope::class)->create([
            'skpd'         => 'Dinas A',
            'kode_akun'    => '1.1.03',
            'nama_akun'    => 'Kas A',
            'tipe'         => 'aset',
            'saldo_normal' => 'debet',
        ]);

        $user->forceFill(['instansi' => null])->save();

        $this->actingAs($user->fresh());

        $this->assertSame('', Tenant::skpd());
        $this->assertFalse(Tenant::valid());
        $this->assertSame(0, AkunMaster::count());
    }
}
