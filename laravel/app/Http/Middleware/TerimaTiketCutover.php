<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CutoverTicket;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terima TIKET SSO dari proksi legacy (migrasi bertahap / cutover).
 *
 * Dipasang pada seluruh group middleware `api` (lihat bootstrap/app.php).
 * Bila request TIDAK membawa tiket, middleware ini tidak melakukan apa pun
 * sehingga alur normal (session/Sanctum) berjalan seperti biasa.
 *
 * Pengaman:
 *  - `X-SIMTKD-Cutover` menandai request datang dari proksi legacy.
 *    Bila ditandai tetapi fitur/secreet belum dikonfigurasi -> 503, BUKAN
 *    diloloskan (mencegah akses tak terautentikasi).
 *  - Tiket diverifikasi dengan hash_equals() + masa berlaku pendek.
 *  - User harus ada dan berstatus 'aktif'.
 *  - Identitas dibatasi pada SKPD/instansi di dalam klaim (defense in depth).
 */
class TerimaTiketCutover
{
    public function handle(Request $request, Closure $next): Response
    {
        $tiket  = trim((string) $request->header(CutoverTicket::HEADER, ''));
        $ditandai = $request->hasHeader(CutoverTicket::HEADER_REQUIRED);

        // Fitur tiket tidak aktif (mis. di lokal) -> header diabaikan.
        if (! CutoverTicket::aktif()) {
            if ($tiket !== '' || $ditandai) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jalur cutover belum diaktifkan pada server ini.',
                ], 503);
            }

            return $next($request);
        }

        // Tanpa tiket: alur normal (endpoint publik / Bearer Sanctum).
        if ($tiket === '') {
            if ($ditandai) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tiket cutover tidak disertakan.',
                ], 401);
            }

            return $next($request);
        }

        $claims = CutoverTicket::verify($tiket);

        if ($claims === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tiket cutover tidak valid atau kedaluwarsa.',
            ], 401);
        }

        $user = User::query()->whereKey((int) $claims['uid'])->first();

        if (! $user || $user->status !== User::STATUS_AKTIF) {
            return response()->json([
                'success' => false,
                'message' => 'Akun pada tiket cutover tidak aktif.',
            ], 401);
        }

        // Defense in depth: identitas tenant pada tiket tidak boleh berbeda
        // dari data user saat ini (mis. user dipindah instansi).
        $skpdTiket = trim((string) ($claims['skpd'] ?? ''));

        if ($skpdTiket !== '' && $skpdTiket !== trim((string) ($user->instansi ?? ''))) {
            return response()->json([
                'success' => false,
                'message' => 'Instansi pada tiket cutover tidak cocok.',
            ], 401);
        }

        // Autentikasi untuk request ini saja (guard default dipakai Sanctum).
        Auth::setUser($user);

        $request->attributes->set('cutover_claims', $claims);

        CutoverTicket::catat($claims, $request->path(), (string) ($request->ip() ?? '-'));

        return $next($request);
    }
}
