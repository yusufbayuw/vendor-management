<?php

declare(strict_types=1);

/**
 * Read-only production configuration checks.
 *
 * Run after deployment configuration and migrations are in place:
 *   php scripts/production-preflight.php
 *
 * This script never outputs environment variable values or secrets.
 */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);

if (! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "[FAIL] Composer dependencies are not installed.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$failures = 0;
$warnings = 0;

$check = static function (bool $condition, string $description) use (&$failures): void {
    fwrite(STDOUT, sprintf("[%s] %s\n", $condition ? 'OK' : 'FAIL', $description));

    if (! $condition) {
        $failures++;
    }
};

$warn = static function (bool $condition, string $description) use (&$warnings): void {
    if (! $condition) {
        fwrite(STDOUT, "[WARN] {$description}\n");
        $warnings++;
    }
};

$check(PHP_VERSION_ID >= 80300, 'PHP meets the minimum version constraint (8.3).');
$check(config('app.env') === 'production', 'APP_ENV is production.');
$check(config('app.debug') === false, 'APP_DEBUG is disabled.');

$url = config('app.url');
$urlParts = is_string($url) ? parse_url($url) : false;
$check(
    is_array($urlParts) && ($urlParts['scheme'] ?? null) === 'https' && ! empty($urlParts['host']),
    'APP_URL is an HTTPS URL with a hostname.'
);

$check(is_string(config('app.key')) && config('app.key') !== '', 'APP_KEY is configured.');
$check(config('session.secure') === true, 'Session cookies require HTTPS.');
$check(config('session.http_only') === true, 'Session cookies are HTTP-only.');
$check(
    in_array(config('session.same_site'), ['lax', 'strict'], true),
    'Session SameSite policy is lax or strict.'
);

$check(config('database.default') === 'mysql', 'Production database driver is MySQL/MariaDB.');

try {
    DB::connection()->select('SELECT 1');
    $check(true, 'Database accepts a read-only query.');
} catch (Throwable) {
    $check(false, 'Database accepts a read-only query.');
}

$queueConnection = config('queue.default');
$asyncConnections = ['database', 'redis', 'sqs', 'beanstalkd'];
$check(in_array($queueConnection, $asyncConnections, true), 'Queue uses an asynchronous connection.');

if (in_array($queueConnection, ['database', 'redis'], true)) {
    $retryAfter = config("queue.connections.{$queueConnection}.retry_after");
    $check(
        is_numeric($retryAfter) && (int) $retryAfter >= 660,
        'Queue retry_after is at least 660 seconds (worker timeout: 600 seconds).'
    );
}

$check(config('queue.failed.driver') === 'database-uuids', 'Failed queue jobs are persisted.');
$check(
    ! in_array(config('mail.default'), ['log', 'array'], true) && filled(config('mail.default')),
    'Production email transport is configured (not log/array).'
);

$phoneMode = config('phone-verification.mode');
$check(in_array($phoneMode, ['manual', 'otp'], true), 'Phone verification is enabled.');

if ($phoneMode === 'otp') {
    $check(config('phone-verification.driver') !== 'log', 'Production OTP never uses the log driver.');
}

$approvalKey = config('supplier-security.approval_signing_key');
$check(is_string($approvalKey) && $approvalKey !== '', 'Supplier approval signing key is configured.');
$warn(
    $approvalKey !== config('app.key'),
    'Use a dedicated SUPPLIER_APPROVAL_SIGNING_KEY rather than the APP_KEY fallback.'
);
$warn(config('logging.channels.single.level') !== 'debug', 'Production log level is debug; consider warning/error and review retention.');

foreach ([
    'storage/app/private',
    'storage/app/private/vendor',
    'storage/framework',
    'storage/logs',
    'bootstrap/cache',
] as $directory) {
    $check(is_dir($root.'/'.$directory) && is_writable($root.'/'.$directory), "{$directory} exists and is writable.");
}

foreach ([
    'public/css/filament/filament/app.css',
    'public/js/filament/filament/app.js',
    'public/js/filament/schemas/schemas.js',
    'public/js/filament/tables/tables.js',
] as $asset) {
    $check(is_file($root.'/'.$asset), "{$asset} is published.");
}

$requiredTables = ['failed_jobs'];

if ($queueConnection === 'database') {
    $requiredTables[] = 'jobs';
}

if (config('session.driver') === 'database') {
    $requiredTables[] = 'sessions';
}

if (config('cache.default') === 'database') {
    $requiredTables[] = 'cache';
}

foreach (array_unique($requiredTables) as $table) {
    try {
        $check(Schema::hasTable($table), "Database has required {$table} table.");
    } catch (Throwable) {
        $check(false, "Database has required {$table} table.");
    }
}

fwrite(STDOUT, sprintf("\nPreflight result: %d failure(s), %d warning(s).\n", $failures, $warnings));
fwrite(STDOUT, "External checks still required: TLS/HTTP, queue and scheduler liveness, backups, restore drill, and staging smoke tests.\n");

exit($failures === 0 ? 0 : 1);
