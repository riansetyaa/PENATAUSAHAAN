<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helper respons JSON dengan bentuk yang sama persis dengan sistem legacy.
 *
 * Frontend vanilla yang sudah ada (62 halaman HTML) membaca `success` dan
 * `message` dari body JSON. Agar migrasi tidak memerlukan perubahan
 * frontend secara masif, bentuk respons TIDAK diubah:
 *
 *     { "success": true, "message": "...", ...data }
 *
 * Contoh pemakaian:
 *     return ApiResponse::ok('Data berhasil dimuat.', ['items' => $items]);
 *     return ApiResponse::fail('Data tidak ditemukan.', [], 404);
 */
final class ApiResponse
{
    /**
     * Respons berhasil.
     *
     * @param  array<string, mixed>  $extra
     * @return \Illuminate\Http\JsonResponse
     */
    public static function ok(string $message = 'OK', array $extra = [], int $status = 200)
    {
        return response()->json(
            array_merge(['success' => true, 'message' => $message], $extra),
            $status
        );
    }

    /**
     * Respons gagal.
     *
     * @param  array<string, mixed>  $extra
     * @return \Illuminate\Http\JsonResponse
     */
    public static function fail(string $message = 'Terjadi kesalahan.', array $extra = [], int $status = 400)
    {
        return response()->json(
            array_merge(['success' => false, 'message' => $message], $extra),
            $status
        );
    }

    /**
     * Respons berhasil TANPA kunci `message`.
     *
     * Dipakai modul AKLAP (peta.simtkd.com): pada sistem legacy, respons
     * suksesnya memang tidak memuat `message` sehingga bentuk JSON harus
     * sama persis agar frontend dan differential test tetap identik.
     *
     * @param  array<string, mixed>  $extra
     * @return \Illuminate\Http\JsonResponse
     */
    public static function okTanpaPesan(array $extra = [], int $status = 200)
    {
        return response()->json(
            array_merge(['success' => true], $extra),
            $status
        );
    }

    /**
     * Validasi gagal (422) — padanan jsonResponse(..., 422) pada sistem legacy.
     *
     * @param  array<string, string>  $errors
     * @return \Illuminate\Http\JsonResponse
     */
    public static function validation(array $errors, string $message = 'Data yang dikirim tidak valid.')
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }
}
