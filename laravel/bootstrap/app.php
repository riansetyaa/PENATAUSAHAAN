<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API dipakai oleh 3 subdomain (simtkd.com / peta / doc) yang saling
        // memanggil. `statefulApi()` mengaktifkan cookie-based session
        // untuk same-origin, sementara subdomain memakai Sanctum token.
        $middleware->statefulApi();

        // Laravel 12 tidak punya Middleware::headers(), jadi header keamanan
        // diterapkan lewat middleware global milik aplikasi.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Group API yang selalu membawa session.
        //
        // Group `api` bawaan Laravel TIDAK menyertakan StartSession, sehingga
        // `Auth::login()` gagal dengan "Session store not set on request".
        // Endpoint auth (login/register/aktivasi) memang butuh session
        // karena frontend utama menggunakan cookie sesi.
        //
        // CSRF sengaja TIDAK diaktifkan pada group ini: seluruh endpoint
        // yang mengubah data diakses memakai header `Authorization: Bearer`
        // (Sanctum), yang tidak pernah di-attach otomatis oleh browser.
        // Endpoint publik (login/register/aktivasi) dilindungi rate limit.
        $middleware->group('api-session', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->alias([
            'peran'  => \App\Http\Middleware\EnsurePeran::class,
            'tenant' => \App\Http\Middleware\EnsureInstansi::class,
        ]);

        // Halaman "login" pada SIM-TKD adalah file HTML statis (login.html),
        // bukan route Laravel. Tanpa override ini, default Laravel
        // (`route('login')`) akan melempar RouteNotFoundException (HTTP 500)
        // setiap kali endpoint API diakses tanpa token.
        //
        // Untuk request API, null berarti "jangan redirect" -> exception dilempar
        // sebagai AuthenticationException dan dirender sebagai JSON 401
        // (lihat shouldRenderJsonWhen di bawah).
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login.html'
        );

        $middleware->redirectUsersTo(
            fn (Request $request) => $request->is('api/*') ? null : '/dashboard.html'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Endpoint API legacy (dan baru) selalu menjawab JSON, tidak pernah
        // HTML "Fatal error". Ini menjaga kompatibilitas dengan frontend
        // vanilla yang parsing response sebagai JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        // Pesan 401 memakai kalimat yang sama dengan sistem legacy
        // ("Tidak terautentikasi.") agar frontend tidak perlu membedakan
        // sumber pesan.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak terautentikasi.',
                ], 401);
            }

            return null;
        });

        // 403 (akses ditolak) juga diseragamkan.
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak.',
                ], 403);
            }

            return null;
        });

        // Validasi Laravel secara bawaan menjawab {message, errors} TANPA
        // kunci `success`. Frontend legacy memeriksa `d.success`, sehingga
        // bentuk bawaan itu akan dianggap gagal although request-nya valid.
        // Bentuk respons diseragamkan ke gaya legacy: {success, message, ...}.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = $e->errors();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors'  => $errors,
            ], 422);
        });
    })->create();
