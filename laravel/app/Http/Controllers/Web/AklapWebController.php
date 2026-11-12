<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Halaman web (Blade) hasil porting bertahap — Fase 4.
 *
 * Fase 4 memindahkan tampilan (bukan hanya API) ke Laravel satu halaman per
 * satu. Halaman pilot = Buku Kas Pengeluaran PPKD yang memakai endpoint
 * `/api/v1/aklap/buku-kas*` (sudah lolos uji paritas), sehingga data dan
 * aturan bisnisnya TETAP satu sumber: controller API yang sama.
 *
 * Autentikasi halaman memakai sesi Laravel (`Auth::attempt`), dan panggilan
 * API dari browser memanfaatkan sesi tersebut lewat Sanctum stateful
 * (lihat `statefulApi()` pada bootstrap/app.php).
 */
class AklapWebController extends Controller
{
    /** GET /masuk — formulir login (tamu saja). */
    public function formulirMasuk(): View
    {
        return view('auth.masuk');
    }

    /** POST /masuk — verifikasi kredensial & mulai sesi. */
    public function masuk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('username', $data['username'])->first();

        // Akun nonaktif ditolak dengan pesan yang sama seperti sistem legacy.
        if ($user !== null && (string) $user->status !== User::STATUS_AKTIF) {
            throw ValidationException::withMessages([
                'username' => 'Akun Anda belum aktif / dinonaktifkan. Hubungi administrator.',
            ]);
        }

        if (! Auth::attempt($data, false)) {
            throw ValidationException::withMessages([
                'username' => 'Username atau kata sandi salah.',
            ]);
        }

        $request->session()->regenerate();

        // Seperti sistem legacy: setelah masuk langsung ke Dashboard AKLAP.
        return redirect()->intended(route('aklap.beranda'));
    }

    /** POST /keluar — akhiri sesi. */
    public function keluar(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('masuk');
    }

    /** GET /aklap — landing/dashboard AKLAP (salinan persis halaman legacy). */
    public function beranda(): View
    {
        return view('aklap.beranda', [
            'legacyBase' => (string) config('aklap.legacy_base', ''),
        ]);
    }

    /** GET /aklap/buku-pengeluaran — halaman pilot Buku Kas Pengeluaran PPKD. */
    public function bukuPengeluaran(): View
    {
        return view('aklap.buku-pengeluaran');
    }
}
