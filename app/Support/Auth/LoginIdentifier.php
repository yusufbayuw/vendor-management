<?php

namespace App\Support\Auth;

use Illuminate\Support\Str;

class LoginIdentifier
{
    /**
     * @return array<string, string>
     */
    public static function credentials(string $identifier, string $password): array
    {
        $identifier = trim($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => Str::lower($identifier),
                'password' => $password,
            ];
        }

        if (static::looksLikePhone($identifier)) {
            return [
                'phone' => static::normalizePhone($identifier),
                'password' => $password,
            ];
        }

        return [
            'username' => Str::lower($identifier),
            'password' => $password,
        ];
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', trim($phone));

        if (blank($digits)) {
            return null;
        }

        if (str_starts_with($digits, '08')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8') && strlen($digits) >= 9) {
            return '62'.$digits;
        }

        return $digits;
    }

    private static function looksLikePhone(string $identifier): bool
    {
        if (! preg_match('/^[+0-9][0-9\s().-]+$/', $identifier)) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $identifier);

        return strlen($digits) >= 8 && strlen($digits) <= 16;
    }
}
