<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Lisensi - SPPG Vendor Management</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f8fafc; color: #0f172a; font-family: Inter, ui-sans-serif, system-ui, sans-serif; padding: 24px; }
        .card { width: min(100%, 640px); background: white; border: 1px solid #e2e8f0; border-radius: 20px; overflow: hidden; box-shadow: 0 24px 70px rgba(15,23,42,.10); }
        .head { padding: 28px 32px; color: white; background: {{ $isValid ? '#059669' : '#dc2626' }}; }
        h1 { margin: 0; font-size: 28px; }
        .head p { margin: 8px 0 0; opacity: .88; }
        .body { padding: 28px 32px; }
        .row { padding: 14px 0; border-bottom: 1px solid #e2e8f0; }
        .row:last-of-type { border-bottom: 0; }
        .label { display: block; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
        code { overflow-wrap: anywhere; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
        a { text-decoration: none; padding: 11px 16px; border-radius: 10px; font-weight: 700; background: #0f172a; color: white; }
        a.secondary { background: #e2e8f0; color: #0f172a; }
    </style>
</head>
<body>
    <main class="card">
        <header class="head">
            <h1>{{ $isValid ? 'Lisensi Aktif' : 'Lisensi Tidak Valid' }}</h1>
            <p>{{ $details['client_name'] ?? 'Belum ada pemegang lisensi' }}</p>
        </header>

        <section class="body">
            <div class="row">
                <span class="label">Produk</span>
                <strong>{{ $details['product'] ?? 'vendor-management' }}</strong>
            </div>

            <div class="row">
                <span class="label">System Signature</span>
                <code>{{ $systemSignature }}</code>
            </div>

            <div class="row">
                <span class="label">Masa Berlaku</span>
                @if (! empty($details['expires_at']))
                    <strong>{{ date('d/m/Y H:i', (int) $details['expires_at']) }}</strong>
                    @if ((int) $details['expires_at'] < time())
                        <span> — kedaluwarsa</span>
                    @endif
                @else
                    <strong>Lifetime</strong>
                @endif
            </div>

            <div class="row">
                <span class="label">Dibuat</span>
                @if (! empty($details['created_at']))
                    {{ date('d/m/Y H:i', (int) $details['created_at']) }}
                @else
                    -
                @endif
            </div>

            <div class="actions">
                @if (! $isValid)
                    <a href="{{ route('license.show') }}">Aktivasi Lisensi</a>
                @endif
                <a class="secondary" href="/">Kembali ke Aplikasi</a>
            </div>
        </section>
    </main>
</body>
</html>
