<?php
/**
 * SIM-TKD - Session & Auth Helpers
 * ============================================
 * Membantu proteksi halaman dan mengelola sesi login.
 */

declare(strict_types=1);

// Pastikan sesi selalu dimulai
if (session_status() === PHP_SESSION_NONE) {
    // Atur parameter cookie sesi (secure hanya jika HTTPS)
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Cek apakah pengguna sudah login.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Proteksi halaman: wajib login, jika tidak redirect ke login.php.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Proteksi halaman: jika sudah login, redirect ke dashboard.
 */
function requireGuest(): void
{
    if (isLoggedIn()) {
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Set data sesi setelah login.
 */
function setUserSession(array $user): void
{
    session_regenerate_id(true); // cegah session fixation
    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['nama']       = $user['nama_lengkap'];
    $_SESSION['email']      = $user['email'];
    $_SESSION['nip']        = $user['nip'] ?? '';
    $_SESSION['instansi']   = $user['instansi'] ?? '';
    $_SESSION['peran']      = $user['peran'];
    $_SESSION['api_token']  = $user['api_token'] ?? '';
}

/**
 * Hapus semua data sesi (logout).
 */
function destroySession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}
