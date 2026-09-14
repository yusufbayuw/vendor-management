# SPPG Vendor Management

Sistem procurement dan vendor management untuk operasional SPPG, mulai dari registrasi dan verifikasi supplier sampai Purchase Request, Purchase Order, pengiriman, penerimaan barang, quality control, invoice, pembayaran, audit, reporting, dan notifikasi.

Project ini dibangun untuk kondisi operasional yang dapat berbeda antar organisasi: satu user dapat memegang beberapa role, tetapi akses data tetap dibatasi oleh scope dan governance policy.

## Stack

- PHP 8.3+
- Laravel 13
- Filament 5
- Filament Shield 4.x
- Spatie Laravel Permission melalui Filament Shield
- Laravel database notifications
- `laravel-notification-channels/webpush` untuk browser push notification
- SQLite untuk development/test; database production dapat dikonfigurasi melalui Laravel

## Core workflow

```text
Supplier Registration / Verification
    -> Purchase Request
    -> Approval
    -> Supplier Allocation
    -> Purchase Order
    -> Supplier Acknowledgement
    -> Delivery Scheduling
    -> Goods Receipt
    -> Quality Control
    -> Reconciliation / Discrepancy
    -> Fulfilled atau Close With Exception
    -> Invoice
    -> Payment
    -> Verification
    -> Closed
```

Satu Purchase Request dapat dialokasikan ke beberapa supplier dan menghasilkan beberapa Purchase Order. Satu Purchase Order juga dapat dikirim melalui beberapa delivery schedule.

### Aturan finansial penting

Nilai dasar invoice tetap mengikuti nilai Purchase Order. Perbedaan kuantitas barang yang diterima tidak mengubah nilai PO secara diam-diam; koreksi finansial dicatat sebagai adjustment yang eksplisit dan dapat diaudit.

## Prinsip arsitektur

- Filament adalah presentation/admin layer; business rule utama berada di Action, Service, dan domain layer.
- Role dan permission dikelola dengan Filament Shield.
- Data scope dipisahkan dari role.
- Satu user dapat memiliki beberapa role dan beberapa scope.
- Separation of duties dan self approval diatur melalui governance policy, bukan hard-coded constraint.
- State transition transaksi tidak dilakukan melalui update status bebas.
- Operasi sensitif harus dapat diaudit.
- Internal user dan supplier memakai portal terpisah tetapi berbagi domain model dan authorization layer yang sama.

## Role

Role bisnis yang tersedia:

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

`panel_user` dipakai sebagai baseline akses panel dan bukan role bisnis utama.

## Data scope

Authorization record-level menggunakan scope berikut:

- `global`
- `organization`
- `sppg_kitchen`
- `supplier`

Role menentukan apa yang boleh dilakukan. Scope menentukan data mana yang boleh disentuh.

Contoh: dua user sama-sama memiliki permission penerimaan barang, tetapi user dengan scope `sppg_kitchen` hanya dapat melihat dan memproses transaksi milik SPPG yang berada dalam scope-nya.

## Governance

Governance policy mendukung operasi lean maupun separation-of-duties yang lebih ketat.

Profile operasional yang tersedia meliputi:

- lean
- standard
- strict

Policy dapat diatur per organisasi dan per proses, termasuk minimum approver, threshold nilai transaksi, self approval, dan kebutuhan override reason.

## Supplier lifecycle

Supplier memiliki lifecycle terkontrol mulai dari registrasi hingga aktif, termasuk:

- profil supplier
- PIC/contact
- rekening bank
- dokumen legal/administratif
- produk yang ditawarkan
- verification dan revision flow
- suspend supplier
- supplier-specific users

Portal supplier tersedia terpisah dari panel internal.

## Procurement dan fulfillment

Fitur utama procurement dan fulfillment meliputi:

- Purchase Request dengan approval
- supplier allocation
- Purchase Order approval dan issuance
- acknowledgement oleh supplier
- split delivery scheduling
- goods receipt
- attachment bukti penerimaan
- QC accepted/rejected quantity
- discrepancy tracking
- reconciliation
- close with exception

## Invoice dan pembayaran

Fitur finance meliputi:

- invoice per Purchase Order
- invoice file upload oleh supplier
- invoice adjustment
- approval invoice
- partial payment
- payment proof attachment
- payment verification
- settlement status

Dokumen transaksi Purchase Order, Goods Receipt, dan Invoice tersedia sebagai halaman A4 yang dapat dicetak atau disimpan sebagai PDF dari browser. Project belum bergantung pada server-side PDF renderer.

## Reporting dan analytics

Panel internal menyediakan procurement reporting dan beberapa operational analytics, termasuk analisis procurement, supplier performance, harga, delivery quality, finance, discrepancy, governance, audit, process performance, bottleneck, operational risk, dan demand forecasting.

CSV procurement report juga tersedia melalui authenticated route dan mengikuti data scope user.

## Audit trail

Perubahan model penting dicatat pada audit log. Payload audit disanitasi sehingga secret dan identifier finansial sensitif tidak disimpan mentah sebagai bagian dari audit payload.

## Private file security

File transaksi dan supplier tidak diekspos sebagai direct public storage URL.

File sensitif menggunakan protected delivery route dengan karakteristik berikut:

- user harus authenticated
- akses diverifikasi berdasarkan entitas, permission, dan data scope
- supplier hanya dapat mengakses file supplier yang terhubung dengannya
- internal user hanya dapat mengakses file transaksi pada organization/SPPG yang menjadi scope-nya
- file image dan PDF dapat dipreview melalui modal
- tipe file yang tidak aman untuk inline preview diarahkan ke download
- response private file menggunakan proteksi seperti `Cache-Control: no-store` dan `X-Content-Type-Options: nosniff`
- file legacy dapat dipindahkan ke private disk ketika pertama kali diakses

Upload baru untuk supplier documents, delivery notes, goods receipt evidence, payment evidence, dan invoice file diarahkan ke private storage.

## Authentication security

Login admin dan supplier mendukung identifier yang dinormalisasi sesuai implementasi aplikasi, termasuk email, username, dan nomor telepon sesuai konfigurasi user.

Captcha login ditangani secara lokal tanpa third-party CAPTCHA provider. CAPTCHA dibuat menggunakan GD, memiliki TTL, dapat di-refresh, dan nilai jawaban tidak disimpan sebagai plaintext di session.

Supplier phone verification mendukung dua mode:

```env
PHONE_VERIFICATION_MODE=manual
```

atau:

```env
PHONE_VERIFICATION_MODE=otp
```

Mode `manual` cocok ketika verifikasi dilakukan oleh administrator. Mode `otp` mengharuskan supplier menyelesaikan verifikasi telepon sebelum memakai portal supplier.

## Notifications

Sistem menggunakan database notification untuk kejadian operasional penting, antara lain:

- supplier submitted / verified / revision / rejected / suspended
- Purchase Request menunggu approval
- Purchase Request siap dialokasikan
- Purchase Order diterbitkan
- Purchase Order dikonfirmasi supplier
- Purchase Order menunggu exception closure
- PO siap dibuatkan invoice
- Goods Receipt menunggu QC
- Invoice submitted / approved
- Payment submitted / verified

Recipient dipilih berdasarkan permission dan data scope. Supplier notification dikirim hanya ke user aktif yang terhubung ke supplier terkait.

## Operational reminders

Scheduler menyediakan reminder operasional harian untuk kondisi seperti:

- delivery besok
- delivery overdue
- Purchase Order belum dikonfirmasi supplier
- dokumen supplier mendekati masa berlaku
- invoice jatuh tempo atau overdue

Reminder dideduplikasi per hari agar event yang sama tidak menghasilkan notification berulang pada satu periode scheduler.

Command manual:

```bash
php artisan vendor:send-reminders
```

Production harus menjalankan Laravel scheduler secara periodik.

## PWA dan Web Push

Admin dan portal supplier memiliki fondasi Progressive Web App:

- manifest
- generated PWA icons
- service worker
- install prompt
- iOS install handling
- notification click handling

Service worker tidak melakukan generic fetch caching untuk halaman authenticated maupun private files. Tujuannya adalah installability dan browser push tanpa menyimpan data transaksi sensitif ke browser Cache Storage.

Web Push menggunakan VAPID dan bersifat optional. Tambahkan konfigurasi berikut di environment production bila browser push diaktifkan:

```env
VAPID_SUBJECT=mailto:admin@example.com
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

Subscription endpoint hanya dapat digunakan oleh user yang authenticated. Push dikirim ke recipient yang sama dengan hasil authorization/scoping database notification sehingga Web Push tidak memiliki jalur recipient terpisah.

## Queue dan scheduler

Project menggunakan database queue secara default pada `.env.example`.

Untuk environment yang memproses queued jobs secara asynchronous, jalankan worker seperti:

```bash
php artisan queue:work
```

Scheduler production harus aktif agar operational reminder berjalan.

## Installation

Clone repository kemudian jalankan:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Atur koneksi database pada `.env`, kemudian:

```bash
php artisan migrate
```

Untuk development, demo data dapat dibuat dengan:

```bash
php artisan migrate:fresh --seed
```

Demo seeder hanya dijalankan pada environment non-production.

## Demo users

Matrix user development tersedia pada:

```text
docs/DEMO_USERS.md
```

Seluruh akun demo menggunakan password:

```text
password
```

Akun tersebut hanya untuk development/testing dan tidak dibuat oleh demo seeder pada production.

## Development checks

Sebelum perubahan dianggap siap, minimal jalankan:

```bash
php artisan migrate:fresh --seed --force
php artisan test
php artisan route:list --except-vendor
vendor/bin/pint --test
```

CI juga memastikan asset utama Filament tersedia setelah Composer install.

## Storage

File sensitif tidak boleh dipindahkan ke public disk hanya untuk mendapatkan URL yang mudah diakses. Gunakan protected file route yang sudah tersedia.

Jika aplikasi memerlukan file publik non-sensitif di masa depan, bedakan dengan jelas antara public asset dan protected business document.

## Production checklist

Sebelum production deployment, pastikan setidaknya:

- `APP_ENV=production`
- `APP_DEBUG=false`
- database production dan credential sudah benar
- queue worker aktif bila menggunakan asynchronous queue
- Laravel scheduler aktif
- private storage persisten dan masuk strategi backup
- HTTPS aktif
- VAPID keys tersedia bila Web Push diaktifkan
- log rotation, monitoring, dan backup database/storage tersedia
- `migrate --force` dijalankan melalui deployment pipeline terkontrol

## Status project

Core procurement flow, authorization, supplier portal, notifications, reporting, analytics, protected files, local CAPTCHA, PWA, dan fondasi Web Push sudah tersedia.

Project masih berada pada fase penyelesaian dan hardening sebelum E2E testing penuh. Perubahan berikutnya sebaiknya tetap mempertahankan tiga lapisan utama: capability permission, record-level scope, dan domain state transition.
