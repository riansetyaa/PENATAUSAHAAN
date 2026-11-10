<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi akses endpoint berdasarkan peran (role).
 *
 * Menggantikan kondisi "peran di-select tapi tidak pernah dicek" pada
 * sistem legacy, di mana setiap user dalam satu SKPD bisa menyetujui
 * jurnal, menghapus akun, dan mengirim dokumen tanda tangan.
 *
 * Pemakaian pada route:
 *   Route::post('jurnal/{id}/approve', ...)->middleware('peran:verifikator,admin-dinas');
 *
 * Peran yang diizinkan memakai nama role Spatie (lihat User::PERAN_TO_ROLE).
 */
class EnsurePeran
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak terautentikasi.',
            ], 401);
        }

        if ($roles === []) {
            return $next($request);
        }

        // Peran dari kolom ENUM legacy (sumber kebenaran) SELALU diterima,
        // selain role Spatie. Ini menjaga kompatibilitas dengan akun lama
        // yang belum pernah di-sync ke tabel role.
        $peranEnum = (string) ($user->peran ?? '');

        // Peta terbalik: nama role Spatie -> nilai ENUM `peran`.
        $enumForRole = array_flip(User::PERAN_TO_ROLE);

        foreach ($roles as $role) {
            if (isset($enumForRole[$role]) && $enumForRole[$role] === $peranEnum) {
                return $next($request);
            }
        }

        $hasRole = method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole($roles);

        if (! $hasRole) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki izin untuk aksi ini.',
            ], 403);
        }

        return $next($request);
    }
}
