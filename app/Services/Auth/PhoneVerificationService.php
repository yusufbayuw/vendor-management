<?php

namespace App\Services\Auth;

use App\Contracts\OtpChannel;
use App\Models\PhoneVerificationCode;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class PhoneVerificationService
{
    public function __construct(private readonly OtpChannel $channel) {}

    public function send(User $user, ?string $ipAddress = null): PhoneVerificationCode
    {
        return DB::transaction(function () use ($user, $ipAddress): PhoneVerificationCode {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (blank($lockedUser->phone)) {
                throw new DomainException('Nomor HP belum tersedia pada akun.');
            }

            if ($lockedUser->phone_verified_at !== null) {
                throw new DomainException('Nomor HP sudah terverifikasi.');
            }

            $cooldown = (int) config('phone-verification.resend_cooldown_seconds', 60);
            $latest = PhoneVerificationCode::query()
                ->where('user_id', $lockedUser->getKey())
                ->where('phone', $lockedUser->phone)
                ->latest('sent_at')
                ->first();

            if ($latest?->sent_at?->addSeconds($cooldown)->isFuture()) {
                $remaining = max(1, now()->diffInSeconds($latest->sent_at->copy()->addSeconds($cooldown)));
                throw new DomainException("Tunggu {$remaining} detik sebelum mengirim ulang OTP.");
            }

            $since = now()->subHour();
            $maxPerUser = (int) config('phone-verification.max_sends_per_hour', 5);
            $sentByUser = PhoneVerificationCode::query()
                ->where('user_id', $lockedUser->getKey())
                ->where('sent_at', '>=', $since)
                ->count();

            if ($sentByUser >= $maxPerUser) {
                throw new DomainException('Batas pengiriman OTP per jam telah tercapai. Coba lagi nanti.');
            }

            if (filled($ipAddress)) {
                $maxPerIp = (int) config('phone-verification.max_sends_per_ip_per_hour', 10);
                $sentByIp = PhoneVerificationCode::query()
                    ->where('ip_address', $ipAddress)
                    ->where('sent_at', '>=', $since)
                    ->count();

                if ($sentByIp >= $maxPerIp) {
                    throw new DomainException('Terlalu banyak permintaan OTP dari jaringan ini. Coba lagi nanti.');
                }
            }

            PhoneVerificationCode::query()
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            $plainCode = (string) random_int(100000, 999999);
            $verification = PhoneVerificationCode::query()->create([
                'user_id' => $lockedUser->getKey(),
                'phone' => $lockedUser->phone,
                'code_hash' => Hash::make($plainCode),
                'ip_address' => $ipAddress,
                'attempt_count' => 0,
                'expires_at' => now()->addSeconds((int) config('phone-verification.expires_in_seconds', 300)),
                'sent_at' => now(),
            ]);

            try {
                $this->channel->send($lockedUser->phone, $plainCode);
            } catch (Throwable $exception) {
                $verification->delete();
                throw $exception;
            }

            return $verification;
        });
    }

    public function verify(User $user, string $code): User
    {
        return DB::transaction(function () use ($user, $code): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (blank($lockedUser->phone)) {
                throw new DomainException('Nomor HP belum tersedia pada akun.');
            }

            if ($lockedUser->phone_verified_at !== null) {
                return $lockedUser;
            }

            $verification = PhoneVerificationCode::query()
                ->where('user_id', $lockedUser->getKey())
                ->where('phone', $lockedUser->phone)
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->latest('sent_at')
                ->lockForUpdate()
                ->first();

            if (! $verification) {
                throw new DomainException('OTP tidak tersedia atau sudah kedaluwarsa. Silakan kirim ulang OTP.');
            }

            $maxAttempts = (int) config('phone-verification.max_attempts', 5);

            if ($verification->attempt_count >= $maxAttempts) {
                $verification->update(['expires_at' => now()]);
                throw new DomainException('Batas percobaan OTP telah tercapai. Silakan kirim ulang OTP.');
            }

            $verification->increment('attempt_count');
            $verification->refresh();

            if (! Hash::check($code, $verification->code_hash)) {
                if ($verification->attempt_count >= $maxAttempts) {
                    $verification->update(['expires_at' => now()]);
                    throw new DomainException('OTP salah dan batas percobaan telah tercapai. Silakan kirim ulang OTP.');
                }

                $remaining = $maxAttempts - $verification->attempt_count;
                throw new DomainException("OTP tidak sesuai. Sisa percobaan: {$remaining}.");
            }

            $verifiedAt = now();
            $verification->update(['verified_at' => $verifiedAt]);
            $lockedUser->forceFill(['phone_verified_at' => $verifiedAt])->save();

            PhoneVerificationCode::query()
                ->where('user_id', $lockedUser->getKey())
                ->whereKeyNot($verification->getKey())
                ->whereNull('verified_at')
                ->update(['expires_at' => now()]);

            return $lockedUser->refresh();
        });
    }
}
