<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi email berisi kode OTP aktivasi akun.
 *
 * Menggantikan includes/mailer.php (410 baris implementasi SMTP manual)
 * dengan Notification + Mail facade Laravel.
 *
 * Bila pengiriman gagal, kode tetap dicatat ke log server agar
 * administrator tetap bisa membantu pengguna (perilaku yang sama dengan
 * sistem legacy pada logs/otp.log.php).
 */
class OtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $otp,
        private readonly string $namaLengkap,
        private readonly string $instansi,
        private readonly int $berlakuMenit = 10,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kode OTP Aktivasi Akun SIM-TKD')
            ->greeting('Halo, ' . $this->namaLengkap . '!')
            ->line('Berikut kode OTP untuk mengaktifkan akun SIM-TKD Anda.')
            ->line('Kode OTP: ' . $this->otp)
            ->line('Kode ini berlaku ' . $this->berlakuMenit . ' menit.')
            ->line('Jika Anda tidak meminta kode ini, abaikan email ini.')
            ->salutation('Sistem SIM-TKD — Politeknik Negeri Bandung');
    }

    /**
     * Fallback ketika mailer gagal: catat kode ke log server.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('[SIM-TKD] Pengiriman OTP gagal: ' . $e->getMessage(), [
            'otp' => $this->otp,
        ]);
    }
}
