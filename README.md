# SPPG Vendor Management

Sistem procurement dan vendor management untuk operasional SPPG, mulai dari onboarding dan verifikasi supplier sampai Purchase Request, Purchase Order, pengiriman, penerimaan dan QC, invoice, pembayaran, audit, reporting, analytics, notification, dan migrasi data lama.

Aplikasi dirancang untuk organisasi dengan kondisi SDM dan governance yang berbeda. Satu user dapat memegang beberapa role, tetapi kemampuan tindakan tetap ditentukan oleh permission, sedangkan akses record dibatasi oleh data scope.

## Stack

- PHP 8.3+
- Laravel 13
- Filament 5
- Filament Shield 4.x
- Spatie Laravel Permission melalui Filament Shield
- Laravel database notifications
- `laravel-notification-channels/webpush` untuk browser push notification
- SQLite untuk development/test
- MySQL/MariaDB atau database Laravel-compatible untuk production
- Vite untuk asset frontend

## Model proses bisnis

UI utama menyederhanakan procurement menjadi **6 business flow**. Approval, allocation, acknowledgement, QC, discrepancy, reconciliation, dan verification tetap ada, tetapi diperlakukan sebagai kontrol atau sub-stage di dalam flow utama, bukan sebagai flow bisnis terpisah.

| Tahap | Flow | Tujuan |
| --- | --- | --- |
| 1 | **PR** | Mencatat kebutuhan barang |
| 2 | **PO** | Membentuk pesanan ke supplier |
| 3 | **Delivery** | Menjadwalkan dan mengelola pengiriman supplier |
| 4 | **Receiving** | Mencatat penerimaan, QC, discrepancy, dan exception |
| 5 | **Invoice** | Menerima dan memeriksa tagihan supplier |
| 6 | **Payment** | Membayar dan memverifikasi pembayaran |

Alur ringkas:

```text
Supplier onboarding
    -> 1. PR
    -> 2. PO
    -> 3. Delivery
    -> 4. Receiving & QC
    -> 5. Invoice
    -> 6. Payment
    -> Closed
```

State internal dapat lebih rinci:

```text
Purchase Request
    -> submit / review / approval
    -> supplier allocation
Purchase Order
    -> approval / issue
    -> supplier acknowledgement (sesuai operational profile)
Delivery
    -> schedule / confirm / in transit / arrived
Receiving
    -> goods receipt / QC
    -> discrepancy / reconciliation / close with exception
Invoice
    -> submit / review / adjustment / approval
Payment
    -> submit / verification
    -> settlement / closed
```

Satu Purchase Request dapat dialokasikan ke beberapa supplier dan menghasilkan beberapa Purchase Order. Satu Purchase Order juga dapat dipenuhi melalui beberapa delivery schedule.

### Aturan finansial penting

Nilai dasar invoice mengikuti nilai Purchase Order. Selisih kuantitas atau kondisi barang yang diterima tidak mengubah nilai PO secara diam-diam. Koreksi finansial dicatat sebagai **invoice adjustment** yang eksplisit dan dapat diaudit.

## Operational profile

Setiap organisasi dapat menggunakan profil operasional yang berbeda:

| Profile | Tujuan |
| --- | --- |
| `lean` | Operasi dengan SDM minimal dan langkah rutin yang dikompresi |
| `standard` | Pemisahan proses normal dengan approval yang tetap terkontrol |
| `strict` | Segregation of Duties yang lebih ketat |

Label aplikasi:

- **Lean - SDM Minimal**
- **Standard**
- **Strict - Segregation of Duties**

### Lean workflow

Pada profile Lean, sistem mengurangi klik tanpa menghilangkan domain rule dan audit trail.

Contoh pada pembuatan PO:

```text
PR approved
    -> pilih supplier
    -> allocation
    -> PO creation
    -> PO approval
    -> PO issue
```

Jika user memiliki seluruh permission yang diperlukan, rangkaian tersebut dapat diproses sebagai satu tindakan bisnis **PO — Buat Pesanan**.

Lean profile juga menggunakan default/prefill pada data rutin, misalnya jadwal delivery dan sisa kuantitas item, sehingga user tidak perlu menginput ulang nilai yang sudah dapat diturunkan dari transaksi sebelumnya.

## Work Inbox / Action Queue

Dashboard internal dan supplier berfungsi sebagai **work inbox**. Sistem hanya menampilkan pekerjaan yang:

1. memang membutuhkan tindakan,
2. sesuai permission user, dan
3. berada dalam data scope user.

Contoh action queue internal:

```text
1. PR — Draft Saya
1. PR — Approval
2. PO — Buat Pesanan
2. PO — Alokasi Supplier
2. PO — Siap Dibuat
2. PO — Approval
2. PO — Siap Terbit
3. Delivery — Konfirmasi
4. Receiving — Jatuh Tempo
4. Receiving — QC
4. Receiving — Exception
5. Invoice — Review
5. Invoice — Approval
6. Payment — Proses
6. Payment — Verifikasi
```

Pada Lean profile, queue yang tidak lagi membutuhkan tindakan terpisah disembunyikan atau dikompresi agar user diarahkan ke tindakan berikutnya yang benar-benar diperlukan.

## Prinsip arsitektur

- Filament adalah presentation/admin layer; business rule utama berada pada Action, Service, dan domain layer.
- Role dan permission dikelola dengan Filament Shield.
- Data scope dipisahkan dari role.
- Satu user dapat memiliki beberapa role dan beberapa scope.
- Separation of Duties dan self approval ditentukan governance policy, bukan hard-coded per role.
- State transition transaksi tidak dilakukan melalui update status bebas.
- Tindakan sensitif harus melalui domain action dan dapat diaudit.
- Internal user dan supplier memakai portal terpisah tetapi berbagi domain model serta authorization layer.
- Workflow UI dapat dibuat lebih lean tanpa melewati rule domain di bawahnya.

## Role

Role bisnis utama:

- `super_admin`
- `central_manager`
- `sppg_manager`
- `requester`
- `procurement`
- `procurement_manager`
- `receiver`
- `quality_control`
- `finance`
- `finance_manager`
- `auditor`
- `supplier_admin`
- `supplier_operator`

`panel_user` digunakan sebagai baseline akses panel dan bukan role bisnis utama.

## Data scope

Authorization record-level menggunakan scope:

- `global`
- `organization`
- `sppg_kitchen`
- `supplier`

**Role/permission menentukan apa yang boleh dilakukan. Scope menentukan record mana yang boleh disentuh.**

Contoh: dua user dapat sama-sama memiliki permission penerimaan barang, tetapi user dengan scope `sppg_kitchen` hanya dapat melihat dan memproses transaksi milik kitchen yang berada dalam scope-nya.

## Governance

Governance policy dapat diatur per organisasi dan proses, termasuk:

- operational profile
- minimum approver
- threshold nilai transaksi
- self approval
- override
- override reason
- separation of duties

Tujuan governance layer adalah membuat aturan approval dapat mengikuti organisasi tanpa menyebarkan pengecekan role secara hard-coded ke seluruh resource.

## Supplier lifecycle

Supplier memiliki lifecycle terkontrol sejak onboarding sampai aktif dan, bila diperlukan, suspend.

Data supplier mencakup antara lain:

- profil/legal entity
- PIC/contact
- rekening bank
- dokumen legal dan administratif
- komoditas/produk yang ditawarkan
- verification dan revision flow
- status supplier
- supplier-specific users

### Mode pengelolaan supplier

Supplier mendukung dua mode:

| Mode | Nilai | Penggunaan |
| --- | --- | --- |
| Portal Supplier | `self_service` | Supplier memiliki akun dan mengelola data melalui portal supplier |
| Dikelola Admin | `admin_managed` | Supplier dikelola internal tanpa wajib memiliki akun portal |

Mode `admin_managed` berguna antara lain untuk supplier existing dan migrasi sistem lama.

### Verifikasi email

Jika email supplier tersedia, sistem dapat mengirim **signed email verification link**.

Production harus menggunakan konfigurasi SMTP yang benar dan `APP_URL` harus sesuai URL publik karena signed URL verifikasi bergantung pada konfigurasi aplikasi.

### Verifikasi WhatsApp / nomor HP

Mode verifikasi telepon:

```env
PHONE_VERIFICATION_MODE=manual
```

atau:

```env
PHONE_VERIFICATION_MODE=otp
```

Pada mode `manual`, alur yang digunakan adalah:

```text
Supplier/PIC memiliki permintaan verifikasi
    -> admin klik "Hubungi PIC via WhatsApp"
    -> sistem membuka wa.me ke nomor PIC
       dengan pesan dan kode referensi terpersonalisasi
    -> PIC mengonfirmasi
    -> admin melakukan tindakan "Verifikasi WhatsApp PIC"
    -> actor, waktu, metode, dan request verification tersimpan
```

Jadi **admin yang menghubungi PIC**, bukan supplier yang diarahkan ke satu nomor WhatsApp admin. Sistem tidak membutuhkan environment variable nomor WhatsApp admin tunggal.

Mode `otp` tetap tersedia untuk integrasi OTP provider. `OTP_CHANNEL=log` hanya sesuai untuk local/testing dan tidak boleh digunakan sebagai mekanisme OTP production.

## Procurement dan fulfillment

Fitur procurement dan fulfillment utama:

- Purchase Request draft, submit, review, approval/rejection
- supplier allocation
- pembuatan Purchase Order
- Purchase Order approval dan issuance
- supplier acknowledgement bila profile membutuhkannya
- split delivery scheduling
- goods receipt
- attachment bukti penerimaan
- QC accepted/rejected quantity
- discrepancy tracking
- reconciliation
- close with exception
- prefill/default untuk mengurangi input rutin
- action queue berdasarkan permission dan scope

## Invoice dan pembayaran

Fitur finance:

- invoice per Purchase Order
- invoice file upload oleh supplier
- invoice review
- invoice adjustment
- approval invoice
- partial payment
- payment proof attachment
- payment verification
- settlement status

Dokumen transaksi Purchase Order, Goods Receipt, dan Invoice tersedia sebagai halaman A4 yang dapat dicetak atau disimpan sebagai PDF dari browser. Aplikasi tidak bergantung pada server-side PDF renderer untuk dokumen tersebut.

## Legacy Data Import

Sistem mendukung migrasi data lama melalui **CSV/XLSX** dari menu **Import Data Lama**.

Jenis data yang didukung:

- supplier
- Purchase Request
- Purchase Order
- Goods Receipt
- invoice
- payment

Import diperlakukan sebagai **data migration**, bukan replay workflow.

Prinsipnya:

- approval historis tidak dibuat ulang sebagai approval request palsu,
- workflow notification dinonaktifkan selama import,
- audit Eloquent tetap berjalan,
- setiap record menyimpan data provenance,
- import dapat dimulai dari transaksi downstream,
- upstream record minimal dapat dibuat sebagai synthetic record bila diperlukan untuk menjaga integritas relasi,
- synthetic record ditandai dengan `workflow_replayed=false`.

Contoh:

```text
PO lama sudah berjalan   -> import PO
Barang sudah diterima    -> import Goods Receipt
Invoice sudah datang     -> import Invoice
Pembayaran sudah terjadi -> import Payment
```

Jika upstream transaction belum tersedia, sistem dapat membuat placeholder relasional minimal seperti PR/PO/invoice synthetic tanpa mengklaim bahwa workflow historis pernah dijalankan di aplikasi.

Dokumentasi format kolom tersedia di [docs/legacy-import.md](docs/legacy-import.md).

## Reporting dan analytics

Panel internal menyediakan procurement report serta analytics operasional, termasuk:

- procurement
- SPPG
- supplier performance
- commodity
- price
- price anomaly
- delivery quality
- finance
- discrepancy
- governance
- audit
- process performance
- process bottleneck
- operational risk
- demand forecasting

Sejumlah halaman analytics memiliki exporter tersendiri.

Procurement report juga tersedia melalui authenticated CSV route dan tetap mengikuti authorization/data scope user.

## Audit trail

Perubahan model penting dicatat ke audit log.

Audit payload disanitasi agar secret dan identifier finansial sensitif tidak disimpan mentah sebagai bagian payload audit.

Selain audit model, beberapa proses menyimpan actor secara eksplisit, termasuk tindakan verifikasi dan keputusan workflow.

## Private file security

Dokumen transaksi dan supplier tidak diekspos sebagai direct public storage URL.

Protected file route menerapkan:

- authenticated access
- authorization berdasarkan entitas, permission, dan data scope
- supplier hanya dapat mengakses file supplier yang berelasi dengannya
- internal user hanya dapat mengakses file transaksi pada organization/SPPG dalam scope-nya
- image/PDF dapat dipreview melalui jalur yang terproteksi
- tipe file yang tidak sesuai untuk inline preview diarahkan ke download
- response private menggunakan proteksi seperti `Cache-Control: no-store` dan `X-Content-Type-Options: nosniff`
- file legacy dapat dipindahkan ke private disk saat pertama kali diakses

Upload supplier document, delivery note, goods receipt evidence, payment evidence, invoice file, dan legacy import disimpan melalui storage private.

## Authentication security

Aplikasi menggunakan **satu entry point login** untuk user internal dan supplier:

```text
/login
```

Route login panel lama tetap tersedia sebagai compatibility redirect:

```text
/admin/login    -> /login
/supplier/login -> /login
```

Setelah autentikasi berhasil, sistem menentukan portal tujuan berdasarkan role akun:

- role internal -> `/admin`
- `supplier_admin` / `supplier_operator` -> `/supplier`

Login mendukung identifier yang dinormalisasi berupa email, username, atau nomor telepon. Intended URL hanya dipertahankan bila masih berada dalam boundary portal yang memang boleh diakses user.

Captcha login ditangani secara lokal tanpa third-party CAPTCHA provider. CAPTCHA:

- dibuat menggunakan GD,
- memiliki TTL,
- dapat di-refresh,
- jawaban tidak disimpan sebagai plaintext di session.

## Notifications

Sistem menggunakan database notification untuk event operasional penting, antara lain:

- supplier submitted / verified / revision / rejected / suspended
- Purchase Request menunggu approval
- Purchase Request siap diproses ke PO
- Purchase Order diterbitkan
- Purchase Order dikonfirmasi supplier
- Purchase Order menunggu exception closure
- Goods Receipt menunggu QC
- PO siap dibuatkan invoice
- Invoice submitted / approved
- Payment submitted / verified

Recipient ditentukan berdasarkan permission dan data scope. Supplier notification hanya dikirim kepada user aktif yang terhubung dengan supplier terkait.

## Operational reminders

Scheduler mengirim reminder operasional untuk kondisi seperti:

- delivery besok
- delivery overdue
- Purchase Order belum dikonfirmasi supplier
- dokumen supplier mendekati masa berlaku
- invoice jatuh tempo atau overdue

Reminder dideduplikasi agar event yang sama tidak menghasilkan notifikasi berulang dalam periode reminder yang sama.

Command manual:

```bash
php artisan vendor:send-reminders
```

Schedule aplikasi saat ini:

```text
07:00 Asia/Jakarta setiap hari
```

Production harus menjalankan Laravel scheduler.

## PWA dan Web Push

Admin dan portal supplier memiliki fondasi Progressive Web App:

- manifest
- generated PWA icons
- service worker
- install prompt
- iOS install handling
- notification click handling

Service worker tidak melakukan generic fetch caching untuk halaman authenticated atau private file. PWA difokuskan pada installability dan browser push tanpa menyimpan transaksi sensitif ke browser Cache Storage.

Web Push menggunakan VAPID dan bersifat optional.

```env
VAPID_SUBJECT=mailto:noreply@example.com
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

Subscription endpoint hanya tersedia untuk authenticated user. Push menggunakan recipient yang sama dengan authorization/scoping database notification; tidak ada jalur recipient terpisah yang melewati authorization aplikasi.

## Licensing

Aplikasi memiliki license gate berbasis **signed license key**.

Setiap instalasi memiliki **system signature** yang diturunkan dari:

```text
APP_KEY + hostname
```

Karena itu perubahan `APP_KEY` atau hostname dapat mengubah system signature dan membutuhkan lisensi yang sesuai dengan instalasi baru.

Jika lisensi belum valid, aplikasi mengarahkan user ke:

```text
/license/activate
```

Status lisensi tersedia di:

```text
/license/status
```

License key dapat diberikan melalui:

```env
LICENSE_KEY=
```

atau dimasukkan melalui halaman aktivasi. Lisensi yang dimasukkan melalui UI disimpan ke private application storage.

### Membuat license key

Command:

```bash
php artisan license:create <system-signature> --client="Nama Client"
```

Lisensi dengan masa berlaku:

```bash
php artisan license:create <system-signature> \
    --client="Nama Client" \
    --expires=2027-12-31
```

Command generator membutuhkan `private_key.pem` pada environment penerbit lisensi.

**Jangan commit private key penerbit lisensi ke repository atau server client.** Aplikasi client hanya membutuhkan public key untuk memverifikasi signature.

## Queue dan scheduler

Default `.env.example` menggunakan database queue.

Worker:

```bash
php artisan queue:work
```

Scheduler:

```bash
php artisan schedule:work
```

atau gunakan cron production Laravel yang memanggil `schedule:run`.

Queue worker dan scheduler harus dikelola process manager/system service agar tetap aktif setelah restart server.

## Installation

Clone repository:

```bash
git clone <repository-url>
cd vendor-management
```

Install dependency:

```bash
composer install
npm install
```

Siapkan environment:

```bash
cp .env.example .env
php artisan key:generate
```

Atur minimal:

- `APP_URL`
- database
- mail
- queue/cache/session
- phone verification
- license
- VAPID bila Web Push digunakan

Migrasi database:

```bash
php artisan migrate
```

Build asset:

```bash
npm run build
```

Untuk development/demo:

```bash
php artisan migrate:fresh --seed
```

Demo seeder hanya ditujukan untuk environment non-production.

## Environment penting

Contoh kelompok konfigurasi production:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://vendor.example.com
LICENSE_KEY=

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

PHONE_VERIFICATION_MODE=manual
PHONE_VERIFICATION_MANUAL_TTL_HOURS=168

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@example.com

VAPID_SUBJECT=mailto:noreply@example.com
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

Lihat `.env.example` sebagai sumber konfigurasi environment yang lebih lengkap.

## Demo users

Matrix user development tersedia pada [docs/DEMO_USERS.md](docs/DEMO_USERS.md).

Seluruh akun demo menggunakan password development:

```text
password
```

Akun demo tidak ditujukan untuk production.

## Development checks

Sebelum perubahan dianggap siap:

```bash
php artisan migrate:fresh --seed --force
php artisan test
php artisan route:list --except-vendor
vendor/bin/pint --test
npm run build
```

CI dan local verification harus dipakai sebagai sumber kebenaran untuk memastikan migration, domain flow, permission, route, dan frontend asset tetap konsisten.

## Production checklist

Sebelum deployment production, pastikan:

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL` sesuai domain publik
- [ ] `APP_KEY` sudah final sebelum membuat system signature lisensi
- [ ] license valid untuk hostname/instalasi production
- [ ] database credential production benar
- [ ] migration dijalankan dengan `php artisan migrate --force`
- [ ] `npm run build` berhasil
- [ ] SMTP production aktif bila email verification digunakan
- [ ] phone verification mode sesuai mekanisme operasional
- [ ] queue worker aktif
- [ ] Laravel scheduler aktif
- [ ] private storage persisten dan masuk strategi backup
- [ ] HTTPS aktif
- [ ] secure session cookie aktif pada HTTPS
- [ ] VAPID keys tersedia bila Web Push digunakan
- [ ] database dan storage memiliki backup
- [ ] log rotation dan monitoring tersedia
- [ ] test suite dan smoke test deployment lulus

## Dokumentasi tambahan

- [Demo Users](docs/DEMO_USERS.md)
- [Legacy Data Import](docs/legacy-import.md)
- [Demo Data Sources](docs/demo-data-sources.md)

## Status project

Core system yang saat ini tersedia mencakup:

- 6-stage procurement flow
- Lean/Standard/Strict operational profile
- role, permission, dan record-level scope
- supplier self-service dan admin-managed mode
- supplier verification melalui email dan WhatsApp/manual flow
- Purchase Request, Purchase Order, delivery, receiving/QC, discrepancy, invoice, dan payment
- work inbox/action queue
- notification dan operational reminder
- reporting dan analytics
- protected private files
- audit trail
- legacy data import
- local CAPTCHA
- PWA dan optional Web Push
- installation-bound licensing

Status production tidak ditentukan hanya dari keberadaan fitur. Sebelum setiap release/deployment, gunakan migration check, automated test, build, dan smoke test pada environment target sebagai gate.

Perubahan lanjutan harus tetap mempertahankan tiga lapisan utama authorization dan workflow:

1. **capability permission**
2. **record-level scope**
3. **domain state transition**

UI boleh dibuat semakin ringkas, tetapi tidak boleh melewati ketiga lapisan tersebut.
