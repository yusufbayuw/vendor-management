<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SystemBoot
{
    private const PRODUCT = 'vendor-management';

    protected static string $publicKey = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAw00dWJgB2YzKYUDiGg+f
fUNzC6E4cvFmYL4GxbHvkVIn3eh9OYLR8wuRIQ6OergcgZ7fehC+ksBPrPWns1kq
ZbL48x3K6xGTCLny/w+u7jCKAUYOleWCTqsCLWh8fTDsMZ0kPiR8K3KleD2ibXX5
utQMMpnm7Rk43QD3EvddOQK7oFDBCjzLR896UjTA37CqROpoXLZzsm7R1GgnRQFH
Wykq0QnxTysv3Rc2rvEdMJd3JVkjFf+8DybCBDT0SceOyqFeMtOrlP9Tdd4GlO0X
q2pMh2EqT2e9wwjsJHwM5QPGwRnU1CD7/EXVCgyeigHpIF2o32G0HQSfaKGsdpMT
1wIDAQAB
-----END PUBLIC KEY-----
PEM;

    public function sig(): string
    {
        $data = (string) config('app.key').'|'.request()->getHost();

        return substr(hash('sha256', $data), 0, 16);
    }

    public function v(): bool
    {
        return Cache::remember('vendor_license_status', 3600, function (): bool {
            $key = config('app.license_key');

            if (! $key && Storage::exists('license.key')) {
                $key = Storage::get('license.key');
            }

            return $this->p(is_string($key) ? $key : null);
        });
    }

    public function p(?string $key): bool
    {
        if (blank($key)) {
            return false;
        }

        try {
            $parts = explode('.', trim($key));

            if (count($parts) !== 2) {
                return false;
            }

            $payloadJson = base64_decode($parts[0], true);
            $signature = base64_decode($parts[1], true);

            if ($payloadJson === false || $signature === false) {
                return false;
            }

            if (openssl_verify($payloadJson, $signature, self::$publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                return false;
            }

            $payload = json_decode($payloadJson, true);

            if (! is_array($payload)) {
                return false;
            }

            if (($payload['product'] ?? null) !== self::PRODUCT) {
                return false;
            }

            if (($payload['system_signature'] ?? null) !== $this->sig()) {
                return false;
            }

            $expiresAt = $payload['expires_at'] ?? null;

            if ($expiresAt !== null && now()->timestamp > (int) $expiresAt) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function store(string $key): bool
    {
        if (! $this->p($key)) {
            return false;
        }

        Storage::put('license.key', trim($key));
        Cache::forget('vendor_license_status');

        return true;
    }

    public function meta(): ?array
    {
        $key = config('app.license_key');

        if (! $key && Storage::exists('license.key')) {
            $key = Storage::get('license.key');
        }

        if (! is_string($key) || blank($key)) {
            return null;
        }

        try {
            $parts = explode('.', trim($key));

            if (count($parts) !== 2) {
                return null;
            }

            $payloadJson = base64_decode($parts[0], true);

            if ($payloadJson === false) {
                return null;
            }

            $payload = json_decode($payloadJson, true);

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
