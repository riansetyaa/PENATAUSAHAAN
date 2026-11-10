<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan dasar untuk seluruh respons.
 *
 * Laravel 12 tidak menyediakan konfigurasi header bawaan (tidak ada
 * `Middleware::headers()`), jadi diterapkan lewat middleware global.
 *
 * Catatan: modul tanda tangan dokumen (doc.simtkd.com) mengirim CSP yang
 * lebih ketat sendiri pada respons HTML dokumen — middleware ini hanya
 * menambah header umum dan TIDAK menimpa CSP yang sudah ada.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'no-referrer',
            'X-Frame-Options'        => 'SAMEORIGIN',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // Respons yang memuat data sensitif (dokumen, tanda tangan, ringkasan
        // keuangan) tidak boleh disimpan cache oleh proxy/browser.
        if ($request->is('api/*') && ! $response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
