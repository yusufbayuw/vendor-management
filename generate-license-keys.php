<?php

declare(strict_types=1);

/**
 * Generate the RSA key pair used by Vendor Management licensing.
 *
 * Cross-platform:
 * - Windows
 * - macOS
 * - Linux
 *
 * Requirements:
 * - PHP CLI
 * - PHP OpenSSL extension
 *
 * Usage:
 *   php generate-license-keys.php
 *   php generate-license-keys.php --force
 *
 * Output:
 *   ./private_key.pem
 *   ./public_key.pem
 *
 * IMPORTANT:
 * - Keep private_key.pem only on the trusted license-issuer machine.
 * - Never deploy private_key.pem to a customer/server.
 * - Never commit either PEM file. This repository ignores *.pem.
 * - The private key is intentionally not password-protected because
 *   app/Console/Commands/GenerateLicense.php currently reads it directly.
 */

const PRIVATE_KEY_FILENAME = 'private_key.pem';
const PUBLIC_KEY_FILENAME = 'public_key.pem';
const RSA_BITS = 3072;

function fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, '[ERROR] '.$message.PHP_EOL);
    exit($exitCode);
}

function info(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function pathFor(string $filename): string
{
    return __DIR__.DIRECTORY_SEPARATOR.$filename;
}

function hasFlag(string $flag): bool
{
    global $argv;

    return in_array($flag, $argv ?? [], true);
}

if (PHP_SAPI !== 'cli') {
    fail('Script ini hanya boleh dijalankan melalui PHP CLI.');
}

if (! extension_loaded('openssl')) {
    fail(
        'Ekstensi PHP OpenSSL belum aktif.'.PHP_EOL.
        'Cek dengan: php -m'.PHP_EOL.
        'Lalu aktifkan extension=openssl pada php.ini yang digunakan PHP CLI.'
    );
}

$requiredFunctions = [
    'openssl_pkey_new',
    'openssl_pkey_export',
    'openssl_pkey_get_details',
    'openssl_sign',
    'openssl_verify',
];

foreach ($requiredFunctions as $function) {
    if (! function_exists($function)) {
        fail("Fungsi {$function} tidak tersedia pada PHP ini.");
    }
}

$privateKeyPath = pathFor(PRIVATE_KEY_FILENAME);
$publicKeyPath = pathFor(PUBLIC_KEY_FILENAME);
$force = hasFlag('--force');

$existingFiles = array_values(array_filter(
    [$privateKeyPath, $publicKeyPath],
    static fn (string $path): bool => is_file($path),
));

if ($existingFiles !== [] && ! $force) {
    info('[STOP] Key file sudah ada:');

    foreach ($existingFiles as $path) {
        info('  - '.$path);
    }

    info('');
    info('Script tidak akan menimpa key yang ada.');
    info('Jika Anda benar-benar ingin membuat pasangan baru, jalankan:');
    info('  php generate-license-keys.php --force');
    info('');
    info('PERINGATAN: mengganti keypair membuat public key aplikasi harus diperbarui');
    info('dan dapat memengaruhi lisensi yang sudah pernah diterbitkan.');

    exit(2);
}

info('Vendor Management License Key Generator');
info('=======================================');
info('PHP       : '.PHP_VERSION);
info('OS        : '.PHP_OS_FAMILY);
info('RSA bits  : '.RSA_BITS);
info('Directory : '.__DIR__);
info('');

$config = [
    'private_key_bits' => RSA_BITS,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$key = openssl_pkey_new($config);

if ($key === false) {
    fail('Gagal membuat RSA private key: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
}

$privateKey = '';

if (! openssl_pkey_export($key, $privateKey)) {
    fail('Gagal mengekspor private key: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
}

$details = openssl_pkey_get_details($key);

if (! is_array($details) || ! isset($details['key']) || ! is_string($details['key'])) {
    fail('Gagal mendapatkan public key dari private key.');
}

$publicKey = $details['key'];

if (file_put_contents($privateKeyPath, $privateKey, LOCK_EX) === false) {
    fail('Gagal menulis '.$privateKeyPath);
}

if (file_put_contents($publicKeyPath, $publicKey, LOCK_EX) === false) {
    @unlink($privateKeyPath);
    fail('Gagal menulis '.$publicKeyPath);
}

/*
 * chmod() is effective on Unix-like systems. On Windows it may have limited
 * effect, but calling it is harmless; Windows file ACLs remain authoritative.
 */
@chmod($privateKeyPath, 0600);
@chmod($publicKeyPath, 0644);

/*
 * Verify that both generated files form a working pair.
 */
$verificationPayload = 'vendor-management-keypair-check:'.bin2hex(random_bytes(16));
$signature = '';

$privateResource = openssl_pkey_get_private((string) file_get_contents($privateKeyPath));
$publicResource = openssl_pkey_get_public((string) file_get_contents($publicKeyPath));

if ($privateResource === false || $publicResource === false) {
    @unlink($privateKeyPath);
    @unlink($publicKeyPath);
    fail('Key file berhasil ditulis tetapi gagal dibaca kembali oleh OpenSSL.');
}

if (! openssl_sign($verificationPayload, $signature, $privateResource, OPENSSL_ALGO_SHA256)) {
    @unlink($privateKeyPath);
    @unlink($publicKeyPath);
    fail('Keypair gagal pada self-test signing.');
}

if (openssl_verify($verificationPayload, $signature, $publicResource, OPENSSL_ALGO_SHA256) !== 1) {
    @unlink($privateKeyPath);
    @unlink($publicKeyPath);
    fail('Keypair gagal pada self-test verification.');
}

$fingerprint = strtoupper(
    implode(
        ':',
        str_split(
            hash('sha256', $publicKey),
            2,
        ),
    ),
);

info('[OK] Keypair berhasil dibuat dan lolos self-test.');
info('');
info('Private key : '.$privateKeyPath);
info('Public key  : '.$publicKeyPath);
info('SHA-256     : '.$fingerprint);
info('');
info('LANGKAH BERIKUTNYA');
info('1. Backup private_key.pem ke lokasi aman/terenkripsi.');
info('2. Jangan pernah upload private_key.pem ke server client.');
info('3. Salin isi public_key.pem ke public key di app/Support/SystemBoot.php.');
info('4. Commit hanya perubahan source code; *.pem sudah di-ignore repository.');
info('5. Setelah public key aplikasi cocok, buat lisensi dengan:');
info('   php artisan license:create <system-signature> --client="Nama Client"');
info('');
info('Public key:');
info('-----------');
info(trim($publicKey));
