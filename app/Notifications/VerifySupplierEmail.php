<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifySupplierEmail extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Verifikasi Email Supplier')
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Email ini digunakan pada akun Vendor Management SPPG.')
            ->line('Klik tombol berikut untuk memverifikasi alamat email Anda.')
            ->action('Verifikasi Email', $verificationUrl)
            ->line("Tautan verifikasi berlaku selama {$minutes} menit.")
            ->line('Jika Anda tidak membuat atau mengubah akun ini, abaikan email ini.');
    }
}
