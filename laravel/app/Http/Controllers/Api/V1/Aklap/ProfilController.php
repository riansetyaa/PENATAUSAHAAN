<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AKLAP — profil akun (dipakai halaman AKLAP untuk menampilkan identitas
 * pemilik token).
 *
 * Diporting dari blok `action=profil` pada peta.simtkd.com/api/aklap.php
 * (`show()`) dan peta.simtkd.com/api/akuntansi.php (`akuntansi()`).
 * Bentuk respons memakai kunci `user` (bukan `data`) dan tanpa `message`.
 */
class ProfilController extends Controller
{
    /**
     * GET /api/v1/aklap/profil
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return $this->okData([
            'user' => [
                'nama'     => (string) ($user?->nama_lengkap ?? ''),
                'username' => (string) ($user?->username ?? ''),
                'instansi' => (string) ($user?->instansi ?? ''),
                'kota'     => (string) ($user?->kota ?? ''),
                'provinsi' => (string) ($user?->provinsi ?? ''),
                'peran'    => (string) ($user?->peran ?? ''),
            ],
        ]);
    }

    /**
     * GET /api/v1/aklap/profil-akuntansi  (action=profil pada akuntansi.php)
     *
     * Berbeda dari profil AKLAP biasa: memuat NIP dan gambar tanda tangan
     * (dipakai blok tanda tangan pada cetakan AKLAP), serta memakai instansi
     * dari tenant aktif.
     */
    public function akuntansi(Request $request)
    {
        $user = $request->user();
        $uid  = (int) ($user?->id ?? 0);

        $ttd = '';

        try {
            $ttd = (string) (DB::table('user_ttd')->where('user_id', $uid)->value('gambar') ?? '');
        } catch (Throwable) {
            $ttd = ''; // tabel TTD belum ada -> biarkan kosong (aman untuk cetak)
        }

        return $this->okData([
            'user' => [
                'nama'       => (string) ($user?->nama_lengkap ?? ''),
                'username'   => (string) ($user?->username ?? ''),
                'instansi'   => Tenant::requireSkpd(),
                'kota'       => (string) ($user?->kota ?? ''),
                'provinsi'   => (string) ($user?->provinsi ?? ''),
                'peran'      => (string) ($user?->peran ?? ''),
                'nip'        => trim((string) ($user?->nip ?? '')),
                'ttd_gambar' => $ttd,
            ],
        ]);
    }
}
