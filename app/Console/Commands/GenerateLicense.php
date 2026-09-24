<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateLicense extends Command
{
    protected $signature = 'license:create
        {signature : System signature dari instalasi target}
        {--client=Licensed Client : Nama pelanggan/pemegang lisensi}
        {--expires= : Tanggal kedaluwarsa YYYY-MM-DD}';

    protected $description = 'Membuat kunci lisensi Vendor Management untuk satu instalasi';

    public function handle(): int
    {
        $targetSignature = trim((string) $this->argument('signature'));
        $expires = $this->option('expires');

        $expiresAt = null;

        if (filled($expires)) {
            $parsed = strtotime((string) $expires.' 23:59:59');

            if ($parsed === false) {
                $this->error('Format --expires tidak valid. Gunakan YYYY-MM-DD.');

                return self::FAILURE;
            }

            $expiresAt = $parsed;
        }

        $payload = [
            'product' => 'vendor-management',
            'system_signature' => $targetSignature,
            'client_name' => (string) $this->option('client'),
            'created_at' => now()->timestamp,
            'expires_at' => $expiresAt,
        ];

        $privateKeyPath = base_path('private_key.pem');

        if (! file_exists($privateKeyPath)) {
            $this->error("Private key tidak ditemukan: {$privateKeyPath}");

            return self::FAILURE;
        }

        $privateKey = file_get_contents($privateKeyPath);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if (! is_string($privateKey) || $privateKey === '' || $payloadJson === false) {
            $this->error('Gagal membaca private key atau membentuk payload lisensi.');

            return self::FAILURE;
        }

        $signature = '';

        if (! openssl_sign($payloadJson, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            $this->error('Gagal menandatangani lisensi.');

            return self::FAILURE;
        }

        $licenseKey = base64_encode($payloadJson).'.'.base64_encode($signature);

        $this->info("License Key untuk signature [{$targetSignature}]:");
        $this->line($licenseKey);

        return self::SUCCESS;
    }
}
