<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base controller untuk seluruh API SIM-TKD.
 *
 * Menyediakan helper ApiResponse agar setiap endpoint memakai bentuk JSON
 * yang konsisten dengan frontend legacy.
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Helper akses cepat ke respons standar.
     */
    protected function ok(string $message = 'OK', array $extra = [], int $status = 200)
    {
        return ApiResponse::ok($message, $extra, $status);
    }

    /**
     * Helper akses cepat ke respons gagal.
     */
    protected function fail(string $message = 'Terjadi kesalahan.', array $extra = [], int $status = 400)
    {
        return ApiResponse::fail($message, $extra, $status);
    }
}
