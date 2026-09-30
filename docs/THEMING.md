# Theme Pack System

Vendor Management memiliki theme engine internal untuk panel Filament. Tujuan utamanya adalah memberi admin pilihan tampilan tanpa mengizinkan upload CSS, eksekusi build, atau perubahan source code dari UI production.

## Prinsip arsitektur

1. Theme yang boleh dipakai didaftarkan di `App\Support\Theme\ThemeRegistry`.
2. Admin hanya menyimpan key theme dan kebijakan color mode ke tabel `theme_settings`.
3. `ThemeManager` memvalidasi pilihan terhadap registry dan meng-cache setting per panel.
4. Render hook Filament memuat satu aset statis `public/css/filament/theme-packs.css`.
5. Runtime memberi atribut `data-app-theme`, `data-app-panel`, dan `data-app-mode` pada elemen `html`.
6. CSS theme selalu di-scope ke atribut tersebut. Theme yang tidak aktif tidak memengaruhi UI.
7. Perubahan setting dicatat oleh audit observer yang sudah digunakan aplikasi.

Tidak ada perintah `npm`, `vite`, `artisan`, atau shell yang dijalankan saat admin mengganti theme.

## Panel

Theme disimpan terpisah untuk:

- `admin` — back-office/operations, lebih padat dan data-oriented.
- `supplier` — portal supplier, kontrol lebih lapang dan lebih nyaman di layar kecil.

## Theme bawaan

### Filament Default

Fallback aman. Aset theme pack tetap termuat, tetapi tidak ada skin Liquid Glass yang diterapkan.

### Liquid Glass

Theme rekomendasi dan default sistem. Prinsipnya adalah **content solid, controls liquid**:

- topbar, sidebar, dropdown, modal, filter, notification, dan chrome memakai material glass;
- table row, data transaksi, dan content utama tetap memiliki opacity tinggi;
- state/status memakai tint ringan, bukan mewarnai seluruh halaman;
- dark mode memakai smoked glass, bukan sekadar mengganti background menjadi hitam;
- ada fallback jika browser tidak mendukung `backdrop-filter`;
- `prefers-reduced-motion` dan `prefers-contrast` dihormati;
- print selalu kembali ke permukaan solid.

## Color mode

Pilihan per panel:

- `user` — mempertahankan pilihan light/dark pengguna Filament;
- `system` — mengikuti preferensi OS/perangkat;
- `light` — memaksa light mode;
- `dark` — memaksa dark mode.

## Menambah theme baru

Tambahkan metadata theme di `ThemeRegistry::all()`:

```php
'corporate' => new ThemePack(
    key: 'corporate',
    label: 'Corporate',
    description: '...',
    previewTone: 'corporate',
),
```

Kemudian tambahkan CSS dengan scope:

```css
html[data-app-theme="corporate"] .fi-topbar {
    /* ... */
}
```

Jangan pernah menerima path CSS, URL stylesheet, atau CSS mentah dari request/admin. Registry adalah allow-list.

## Deployment

Migrasi:

```bash
php artisan migrate --force
```

Sinkronkan permission baru:

```bash
php artisan db:seed --class=RoleAndPermissionSeeder --force
```

Theme pack tidak membutuhkan build frontend. File CSS sudah berada di `public/css/filament/theme-packs.css`.

Setelah deployment, buka:

`/admin/settings/appearance`

Super Admin selalu dapat mengakses halaman ini. Role lain membutuhkan permission `appearance.view` dan `appearance.manage`.

## Rollback aman

Admin dapat memilih **Filament Default** kapan saja. Jika tabel setting belum tersedia atau value database tidak valid, `ThemeManager` otomatis menggunakan theme yang terdaftar dan mode aman.

Untuk rollback migration:

```bash
php artisan migrate:rollback --step=1
```
