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

function openSslErrors(): string
{
    $errors = [];

    while (($error = openssl_error_string()) !== false) {
        $errors[] = $error;
    }

    return $errors === [] ? 'unknown OpenSSL error' : implode(' | ', $errors);
}

/**
 * @return array{path:string,temporary:bool,source:string}
 */
function resolveOpenSslConfig(): array
{
    $candidates = [];

    foreach (['OPENSSL_CONF', 'SSLEAY_CONF'] as $environmentVariable) {
        $value = getenv($environmentVariable);

        if (is_string($value) && trim($value) !== '') {
            $candidates[] = [
                'path' => trim($value),
                'source' => $environmentVariable,
            ];
        }
    }

    $phpDirectory = dirname(PHP_BINARY);
    $phpParentDirectory = dirname($phpDirectory);

    foreach ([
        $phpDirectory.DIRECTORY_SEPARATOR.'openssl.cnf',
        $phpDirectory.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
        $phpDirectory.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
        $phpDirectory.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'openssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
        $phpParentDirectory.DIRECTORY_SEPARATOR.'openssl.cnf',
        $phpParentDirectory.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
        $phpParentDirectory.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
    ] as $path) {
        $candidates[] = [
            'path' => $path,
            'source' => 'PHP installation',
        ];
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $programFiles = getenv('ProgramFiles');
        $programFilesX86 = getenv('ProgramFiles(x86)');
        $userProfile = getenv('USERPROFILE');

        $join = static fn (string ...$segments): string => implode(DIRECTORY_SEPARATOR, $segments);

        if (is_string($programFiles) && $programFiles !== '') {
            $candidates[] = [
                'path' => $join($programFiles, 'Common Files', 'SSL', 'openssl.cnf'),
                'source' => 'Windows Common Files',
            ];
        }

        if (is_string($programFilesX86) && $programFilesX86 !== '') {
            $candidates[] = [
                'path' => $join($programFilesX86, 'Common Files', 'SSL', 'openssl.cnf'),
                'source' => 'Windows Common Files (x86)',
            ];
        }

        $systemDrive = getenv('SystemDrive');
        $systemDrive = is_string($systemDrive) && $systemDrive !== '' ? rtrim($systemDrive, '\\/') : 'C:';

        $candidates[] = [
            'path' => $join($systemDrive, 'usr', 'local', 'ssl', 'openssl.cnf'),
            'source' => 'legacy Windows default',
        ];

        if (is_string($userProfile) && $userProfile !== '') {
            $herdBin = $join($userProfile, '.config', 'herd', 'bin');
            $phpFolder = basename($phpDirectory);

            foreach ([
                $join($herdBin, $phpFolder, 'openssl.cnf'),
                $join($herdBin, $phpFolder, 'ssl', 'openssl.cnf'),
                $join($herdBin, $phpFolder, 'extras', 'ssl', 'openssl.cnf'),
                $join($herdBin, $phpFolder, 'extras', 'openssl', 'openssl.cnf'),
            ] as $path) {
                $candidates[] = [
                    'path' => $path,
                    'source' => 'Laravel Herd',
                ];
            }
        }
    }

    foreach ($candidates as $candidate) {
        $path = $candidate['path'];

        if (is_file($path) && is_readable($path)) {
            return [
                'path' => $path,
                'temporary' => false,
                'source' => $candidate['source'],
            ];
        }
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'vendor-openssl-');

    if ($temporaryPath === false) {
        fail('Tidak dapat membuat openssl.cnf sementara di '.sys_get_temp_dir().'.');
    }

    $minimalConfig = <<<'CNF'
[ req ]
default_bits = 3072
default_md = sha256
distinguished_name = req_distinguished_name
prompt = no

[ req_distinguished_name ]
CN = Vendor Management License Signing
CNF;

    if (file_put_contents($temporaryPath, $minimalConfig.PHP_EOL, LOCK_EX) === false) {
        @unlink($temporaryPath);
        fail('Tidak dapat menulis openssl.cnf sementara.');
    }

    return [
        'path' => $temporaryPath,
        'temporary' => true,
        'source' => 'temporary minimal config',
    ];
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
    'openssl_pkey_get_private',
    'openssl_pkey_get_public',
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

$opensslConfig = resolveOpenSslConfig();

info('Vendor Management License Key Generator');
info('=======================================');
info('PHP       : '.PHP_VERSION);
info('OS        : '.PHP_OS_FAMILY);
info('OpenSSL   : '.(defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'unknown'));
info('RSA bits  : '.RSA_BITS);
info('Directory : '.__DIR__);
info('Config    : '.$opensslConfig['path']);
info('Source    : '.$opensslConfig['source']);
info('');

$config = [
    'config' => $opensslConfig['path'],
    'private_key_bits' => RSA_BITS,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'digest_alg' => 'sha256',
];

$key = null;

try {
    while (openssl_error_string() !== false) {
        // Clear stale errors before key generation.
    }

    $key = openssl_pkey_new($config);

    if ($key === false) {
        fail(
            'Gagal membuat RSA private key.'.PHP_EOL.
            'OpenSSL config: '.$opensslConfig['path'].PHP_EOL.
            'Detail: '.openSslErrors()
        );
    }

    $privateKey = '';

    if (! openssl_pkey_export($key, $privateKey, null, $config)) {
        fail('Gagal mengekspor private key: '.openSslErrors());
    }

    $details = openssl_pkey_get_details($key);

    if (! is_array($details) || ! isset($details['key']) || ! is_string($details['key'])) {
        fail('Gagal mendapatkan public key dari private key: '.openSslErrors());
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
        fail('Key file berhasil ditulis tetapi gagal dibaca kembali oleh OpenSSL: '.openSslErrors());
    }

    if (! openssl_sign($verificationPayload, $signature, $privateResource, OPENSSL_ALGO_SHA256)) {
        @unlink($privateKeyPath);
        @unlink($publicKeyPath);
        fail('Keypair gagal pada self-test signing: '.openSslErrors());
    }

    if (openssl_verify($verificationPayload, $signature, $publicResource, OPENSSL_ALGO_SHA256) !== 1) {
        @unlink($privateKeyPath);
        @unlink($publicKeyPath);
        fail('Keypair gagal pada self-test verification: '.openSslErrors());
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
} finally {
    if ($opensslConfig['temporary']) {
        @unlink($opensslConfig['path']);
    }
}
