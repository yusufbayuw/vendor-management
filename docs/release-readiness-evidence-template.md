# SPPG Vendor Management — Release readiness evidence template

**Status: TEMPLATE ONLY — no staging, backup, restore, or production host checks have been executed by this document.**

Clone this checklist for each release candidate, store redacted evidence in the PM's authorized location, and never commit actual credentials, private upload content, customer data, backup files or full error traces to GitHub. **Operators must not run any live-service-changing command without explicit PM and Owner approval.**

## Identification

| Field | Record (no secrets) |
|---|---|
| Release SHA / Composer lock SHA-256 | |
| Environment and service alias (no IP/secret) | |
| Staging operator and approver | |
| Validation window (Asia/Jakarta and UTC) | |
| CI run URL and job conclusions | |
| Backup archive IDs, period and retention class | |
| Approved RPO / RTO targets | |
| Previous known-good release SHA | |
| PM/Owner release decision reference | |

Status definitions:

- **PASS**: executed on the stated environment; recorded redacted result and timestamp.
- **FAIL**: executed and the acceptance criterion was not met.
- **BLOCKED**: cannot execute; record who must grant access, approve, or provide infrastructure.
- **NOT TESTED**: not attempted or evidence is absent.

## Release gate matrix

Fill every row with **status**, execution time, environment, responsible operator, evidence reference and corrective issue if necessary. A CI run does **not** count as production/staging operational verification.

| Gate | Acceptance proof / safe observed outcome | Status | Evidence / owner |
|---|---|---|---|
| Dependency security | `composer audit --no-interaction` on released lock; zero active advisories | NOT TESTED | |
| PHP / Composer / assets | Matching PHP runtime; installed lock, published Filament CSS/JS; frontend loads | NOT TESTED | |
| MySQL/MariaDB | Dedicated staging DB; read-only connectivity, migrations/status, no prod endpoint | NOT TESTED | |
| Laravel preflight | `php scripts/production-preflight.php` exits 0; warnings triaged; redacted summary | NOT TESTED | |
| HTTPS / session | Valid cert and redirect, real browser secure/HttpOnly/SameSite session | NOT TESTED | |
| Supplier email / OTP | Controlled staging registration, signed email link, OTP or manual mode; no production messages | NOT TESTED | |
| Private file access | Own supplier documents permitted, other supplier and payment proof denied as appropriate | NOT TESTED | |
| Scope / payment approval | Cross-org and cross-kitchen actor denied; invoice/payment governance and replay checks | NOT TESTED | |
| Queue / Supervisor | Running worker, correct timeout/retry, one controlled staging job processed | NOT TESTED | |
| Failed jobs / recovery | Counts and error redaction verified; safe operator triage/retry demonstrated in staging | NOT TESTED | |
| Scheduler | Exactly one configured cron, task run evidence at expected Asia/Jakarta time | NOT TESTED | |
| Backup DB + uploads | Consistent approved write-quiescent snapshot pair, complete and internally valid | NOT TESTED | |
| Off-host & integrity | Backup on **independent** restricted host; SHA-256 passes **there**; retention recorded | NOT TESTED | |
| Isolated restore drill | Restore credential cannot touch prod; DB and private files verified in isolated staging | NOT TESTED | |
| Recovery objectives | Real elapsed restore time and backup age meet PM-approved RTO/RPO | NOT TESTED | |
| Monitoring & incident alerts | HTTPS, worker, scheduler, DB, disk, jobs, backup checks; test incident notification delivered | NOT TESTED | |
| Code rollback | Previous code/assets/lock recoverable, migrations reviewed for backward compatibility; staging rehearsal | NOT TESTED | |
| Business staging UAT | Tim A approves registration, supplier suspension history and Lean/Standard/Strict PR→payment | NOT TESTED | |
| Release authorization | Explicit PM and Project Owner approval; incident/rollback owner on call | BLOCKED | Awaiting completed gates |

## Operator checklist (approved **staging** only)

Use `APP_ROOT` and `PHP` from the release runbook. These examples inspect state and must be executed with least privilege. Do not copy sensitive output into evidence.

```bash
cd "$APP_ROOT"
git rev-parse HEAD
"$PHP" -v
"$PHP" scripts/production-preflight.php
"$PHP" artisan migrate:status
"$PHP" artisan schedule:list
supervisorctl status
df -h "$APP_ROOT"
df -i "$APP_ROOT"
curl -fsS --max-time 10 https://YOUR-STAGING-HOST/up >/dev/null
```

- Verify HTTP TLS chain and session cookie flags in the browser/network inspector; **never** use `curl -k` to mark TLS PASS.
- Record that `DB_CONNECTION`/DB host target the isolated staging database **before** a staging migration or restore. Never run `migrate:fresh` against production.
- Record aggregate `jobs` and `failed_jobs` counts and scheduler/worker last-run times; do not expose job payloads or exception traces.
- Staging email/SMS/push transports must be isolated/test-only. Test actual sender/recipient delivery in a controlled sandbox, not only `MAIL_MAILER=smtp` configuration.
- Use test-owned supplier identities for cross-supplier and disabled-account attempts, ensure unauthorized requests return 403/404, and verify suspended suppliers cannot initiate forbidden writes.
- A successful `schedule:list` is **not** proof cron ran. Use a controlled scheduled execution and check redacted timestamp.
- Use existing Supervisor/aaPanel monitoring, a small independent HTTPS check and email/notification on failures. Record actual receipt of at least one safe test alert.

## Backup/restore drill evidence (operator only)

**No restore command is authorized by this template.** Follow section 5–6 of [production-operations.md](production-operations.md) only on a manually verified isolated host, after approval.

| Evidence item | Recorded value (redacted / aggregate only) |
|---|---|
| Approval reference and confirmed isolated target | |
| Snapshot method (quiesced writes / coordinated snapshots) | |
| Paused queue writers and active upload/import count at snapshot | |
| Source database/schema and private-storage class (no connection strings) | |
| DB dump filename token/size; gzip integrity result | |
| Storage archive token/size; tar listing integrity result | |
| Off-host transfer completed / access restrictions | |
| SHA-256 verification on **independent host** | |
| Restore began / ended (timestamps) | |
| Measured elapsed time and approved RTO; result | |
| Backup age and approved RPO; result | |
| Aggregate table count reconciliation (not personal records) | |
| Representative private document recoverable and unauthorized access denied | |
| Separate restore credentials / no production network route | |
| Operator and PM witness / follow-up issue | |

After the drill, separately confirm the previous *known-good* code commit, matching `composer.lock` and built assets can be reselected on staging. Never blindly reverse migrations or overwrite newer production database writes during rollback.

## Decision

**GO** requires every mandatory operational and security gate PASS plus explicit PM/Owner approval. If any mandatory gate is FAIL, BLOCKED or NOT TESTED: **NO-GO**. Record decision, date, reviewer, known residual risks and corrective actions without secrets.

**Current baseline (25 October 2026):** `main` SHA `fa440db94384217e8255746c46746fd9a718d658`; [GitHub CI #40151268300](https://github.com/yusufbayuw/vendor-management/actions/runs/40151268300) passed `test` and `MariaDB legacy import`. This verifies **code-level gates only**. Staging, offsite backup, isolated restore, monitor alerts and rollback exercises are **BLOCKED on operator/environment evidence** until performed. **NO-GO**.
