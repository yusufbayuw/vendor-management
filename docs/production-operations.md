# Production operations — SPPG Vendor Management

Owner: Tim Teknis B. This is a **manual, approval-gated** release procedure for Laravel 13 / Filament 5 on aaPanel + nginx + PHP 8.4 + MariaDB. Adapt paths, OS user, and credentials to the actual host. Never run against production without explicit approval from the Project Manager and owner.

This document is not evidence that backup, restore, HTTPS, queue or smoke tests already passed. Keep a release evidence log with timestamps, release SHA, operator, and results. **Never upload passwords, private files, real data, .env, VAPID private keys, or full logs containing credentials to GitHub.**

## 1. Go/no-go prerequisites

1. The intended commit is reviewed and CI passes: `composer audit --no-interaction` (zero active advisories), clean Composer install, SQLite migration/seed and targeted tests, Pint, route list, Filament assets, Vite build, MariaDB legacy-import tests.
2. The supplier email registration fix in PR #6 (or its merged successor) has separate authorization and end-to-end signoff. A passing dependency CI does not imply PR #6 is released.
3. Staging uses an isolated database, private storage and credentials; the production database must never be cloned to public/test environments without proper sanitization.
4. Confirm maintenance window, user notification, rollback owner and a verified recent backup. Do **not** run `migrate:fresh`, `db:wipe`, `db:seed` with demo users, or `queue:clear` in production.
5. Capture current commit SHA, runtime PHP version, dependency lock hash and asset version. Confirm the PHP binary used by aaPanel site, worker, cron and CLI is compatible and consistent.

## 2. Configuration baseline

Use a production-only `.env` file outside version control. Values listed below are examples; secrets must come from the production secret store. Do not paste real values into tickets or CI.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-production-host.example
LOG_LEVEL=warning
DB_CONNECTION=mysql
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
QUEUE_FAILED_DRIVER=database-uuids
DB_QUEUE_RETRY_AFTER=660
FILESYSTEM_DISK=local
PHONE_VERIFICATION_MODE=manual
MAIL_MAILER=smtp
```

Also configure strong unique `APP_KEY`, `SUPPLIER_APPROVAL_SIGNING_KEY`, database credentials, mail sender/transport and actual `LICENSE_KEY`. Configure VAPID keys only if Web Push is enabled. Use `OTP_CHANNEL=log` **only** when OTP mode is not used. `APP_URL` must match the public HTTPS hostname so signed verification links work. Private supplier documents use `storage/app/private/vendor` (`vendor-private` disk) and must not be mapped under nginx `public/`.

The queue's `retry_after=660` must exceed the worker's `--timeout=600`. Operate **one** default-queue worker initially; add concurrency only after verifying idempotency/lock behavior. The legacy import job has `tries=1` and `timeout=600`. Failed imports require operator triage, not blind automated retries.

## 3. Repeatable release, after backup and approval

Set `APP_ROOT` to your checked-out release directory and `PHP` to the correct CLI binary (for example, `/www/server/php/84/bin/php`). Run commands as the application/deployment user with only the necessary permissions. The code release mechanism (symlink, atomic checkout or controlled upload) must preserve `.env` and persistent `storage/`; do not overwrite those on deploy.

```bash
cd "$APP_ROOT"
"$PHP" -v
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
composer audit --no-interaction
npm ci
npm run build
mkdir -p storage/app/private/vendor storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
"$PHP" artisan filament:upgrade
"$PHP" artisan optimize:clear
"$PHP" artisan migrate --force
"$PHP" scripts/production-preflight.php
"$PHP" artisan route:list --except-vendor
"$PHP" artisan schedule:list
"$PHP" artisan queue:restart
"$PHP" artisan optimize
```

`production-preflight.php` is **read-only**: it checks configuration, read-only DB connectivity and required tables, writable paths and Filament assets; it deliberately does not change environment values or create missing directories. Its return code is nonzero on failed gates and it never prints secrets. Run it after migrations, before opening traffic. The external HTTP, queue, scheduling and backup evidence checks below are mandatory and cannot be inferred from preflight success.

On an **existing live installation**, coordinate queue drain and planned maintenance before code/schema changes. Confirm no critical import job is processing before restarting workers. Keep a known-good previous release available. Avoid destructive migration or emergency schema rollback without a separate plan.

## 4. Queue and scheduler

Example Supervisor program; replace application path, PHP CLI path and user. Keep supervisor config outside the web root, apply with `supervisorctl reread && supervisorctl update`, then verify `supervisorctl status`.

```ini
[program:sppg-vendor-queue]
command=/www/server/php/84/bin/php /path/to/vendor-management/artisan queue:work database --queue=default --sleep=3 --tries=1 --timeout=600
directory=/path/to/vendor-management
user=www
numprocs=1
autostart=true
autorestart=true
startsecs=3
stopwaitsecs=700
redirect_stderr=true
stdout_logfile=/path/to/vendor-management/storage/logs/queue-worker.log
```

Schedule exactly one cron entry per application installation (for example, through aaPanel scheduled tasks), running every minute:

```cron
* * * * * cd /path/to/vendor-management && /www/server/php/84/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

The registered reminder `vendor:send-reminders` executes daily at 07:00 **Asia/Jakarta** via scheduler. Validate execution separately through scheduler/notification evidence; `schedule:list` only proves registration, not that cron ran. Observe `queue:failed` and `failed_jobs` without dumping payloads, because they may include private information.

## 5. Backups: both database and private uploads

All following commands are examples for a **trusted operator** on a secure server, with restrictive filesystem permissions and a storage target separate from the production disk. Protect backups at rest and in transit. Create a restricted MariaDB defaults file containing credentials (`chmod 600`), instead of including passwords on the process command line. Verify actual binary names (`mariadb-dump` vs `mysqldump`) on the host.

```bash
set -euo pipefail
umask 077
BACKUP_ROOT=/secure/offsite-staging
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
# DB_NAME is the approved production database name; do not paste credentials here.
mariadb-dump --defaults-extra-file="$HOME/.vendor-backup.cnf" \
  --single-transaction --quick --routines --triggers --events "$DB_NAME" \
  | gzip -9 > "$BACKUP_ROOT/db-$STAMP.sql.gz"
tar -C "$APP_ROOT" -czf "$BACKUP_ROOT/storage-$STAMP.tar.gz" storage/app
sha256sum "$BACKUP_ROOT/db-$STAMP.sql.gz" "$BACKUP_ROOT/storage-$STAMP.tar.gz" \
  > "$BACKUP_ROOT/checksums-$STAMP.txt"
chmod 600 "$BACKUP_ROOT"/*"$STAMP"*
```

Validate archive integrity using `gzip -t`, `tar -tzf` and stored SHA-256 hashes. Back up app/release SHA, package lock hash, database schema metadata, queue configuration and secrets **references** (not secret values). Store `.env`/keys separately in an encrypted authorized secret recovery system; an encrypted backup is still sensitive data. Configure scheduled backups, off-host retention, access control, restore ownership, and alarms on backup failure. Choose RPO/RTO with the PM; no RPO/RTO claim is valid before a timed restore drill.

## 6. Mandatory isolated restore drill (never production)

1. Provision a temporary restricted MariaDB **restore database** and separate staging filesystem. Confirm the active DB name is not the restore target.
2. Verify backups' SHA-256 and archive integrity. Restore with `gunzip -c <backup> | mariadb --defaults-extra-file=<secured-file> <restore-db>` **only after manually validating the target**; restore `storage/app` into the isolated staging path, preserving permissions and private files.
3. Use a distinct staging `.env` / signing keys and isolated queues. Prevent real email, SMS, Web Push and supplier notifications from being dispatched during restore; staging must not contact production integrations.
4. Read-check representative organization/supplier/document records and a private attachment against expected snapshots without exporting real rows to issue logs. Validate `migrate:status`, permissions, storage accessibility and relevant workflows on staging.
5. Record date/time, tested backup IDs/hashes, DB and file validation results, elapsed recovery time, operator and corrective actions. Destroy the isolated environment under the approved retention policy.

**Release gate:** A green CI and preflight do not replace an actual restore drill. If restore was not performed, production readiness is *not verified*.

## 7. Smoke, observability and rollback

**Staging smoke:** check public HTTPS `GET /up`; login/registration with email and phone-only cases; signed email-verification URL; supplier document unauthorized/authorized access; staff organization/kitchen isolation; payment/approval permissions; one **small** dry-run legacy import followed by a permitted real import only in staging. Validate asset loading and correct redirect/status, not only `200 OK`.

**Production smoke (non-destructive):** verify TLS certificate and HTTPS redirect, `GET /up`, expected panels rendering without JS console errors, application/queue/scheduler status, no anomalous spikes in `failed_jobs`, disk/DB health, backup freshness and existing-user sign-in by an authorized operator. Avoid test payments and test supplier records on the real database.

**Monitoring:** alert on HTTP health errors, TLS expiry, PHP/nginx failures, abnormal 5xx rate, database availability, disk/inode saturation, queue age/backlog/failures, cron/scheduler missed execution, backup-age/hash/integrity failures, security/permission-denied spikes and new `composer audit` advisories. Keep logs access-restricted and rotated; redact signed URLs, sessions, credentials and supplier private data.

**Rollback:** stop release and notify PM; preserve diagnostic metadata. Roll back code/assets using the previous verified commit/release and matching `composer.lock`, then clear framework caches and restart workers under a maintenance window. Do **not** automatically reverse DB migrations or import batches. If database restoration is required, request explicit incident/PM approval, account for writes made since backup, and restore the paired database + private file archive to a validated target. Perform post-rollback smoke and record final status.

## 8. Evidence required to mark Issue #8 done

- PR/commit SHA, CI links (including `composer audit`, frontend build and MariaDB), Filament Shield/Livewire/supplier auth smoke outcomes.
- Signed deployment go/no-go decision; PHP/nginx/SSL/storage/queue/scheduler configuration and observed runtime status, **without secrets**.
- Backup archive hashes and independent restore-drill outcome with recovery measurements.
- Verified rollback procedure/previous release and alerting ownership.
- Remaining defects recorded as issues with severity, scoped owner and release blocker classification.
