<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pastikan user yang sedang login punya instansi/SKPD terisi.
 *
 * Menggantikan `requireInstansi()` pada sistem legacy, yang melakukan
 * jsonResponse(403) bila session instansi kosong.
 *
 * Sifat fail-closed: tanpa instansi, seluruh data multi-tenant tidak boleh
 * diakses. Endpoint ini wajib dipasang pada modul yang membutuhkan data
 * SKPD (penerimaan, belanja, AKLAP, dokumen).
 */
class EnsureInstansi
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak terautentikasi.',
            ], 401);
        }

        if (trim((string) ($user->instansi ?? '')) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Instansi akun belum diatur. Hubungi administrator.',
            ], 403);
        }

        return $next($request);
    }
}
