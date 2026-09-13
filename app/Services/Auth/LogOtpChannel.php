<?php

namespace App\Services\Auth;

use App\Contracts\OtpChannel;
use Illuminate\Support\Facades\Log;

class LogOtpChannel implements OtpChannel
{
    public function send(string $phone, string $code): void
    {
        Log::channel(config('phone-verification.log_channel', 'stack'))->info('Phone verification OTP', [
            'phone' => $phone,
            'otp' => $code,
            'expires_in_seconds' => (int) config('phone-verification.expires_in_seconds', 300),
        ]);
    }
}
