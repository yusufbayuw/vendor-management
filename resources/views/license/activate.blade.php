<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivasi Lisensi - SPPG Vendor Management</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0f172a; color: #e2e8f0; font-family: Inter, ui-sans-serif, system-ui, sans-serif; padding: 24px; }
        .card { width: min(100%, 560px); background: #111827; border: 1px solid #334155; border-radius: 20px; padding: 32px; box-shadow: 0 24px 80px rgba(0,0,0,.35); }
        h1 { margin: 0 0 8px; font-size: 30px; }
        .muted { color: #94a3b8; line-height: 1.6; }
        label { display: block; margin: 24px 0 8px; font-weight: 600; }
        code { display: block; padding: 14px 16px; border-radius: 12px; background: #020617; border: 1px solid #334155; color: #cbd5e1; overflow-wrap: anywhere; user-select: all; }
        textarea { width: 100%; min-height: 130px; resize: vertical; border-radius: 12px; border: 1px solid #475569; background: #020617; color: #f8fafc; padding: 14px 16px; font: inherit; }
        textarea:focus { outline: 2px solid #f59e0b; outline-offset: 2px; }
        button { width: 100%; margin-top: 20px; border: 0; border-radius: 12px; padding: 14px 18px; background: #f59e0b; color: #111827; font-weight: 800; cursor: pointer; }
        .alert { margin-top: 18px; padding: 12px 14px; border-radius: 10px; background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.35); color: #fca5a5; }
        .error { color: #fca5a5; margin-top: 8px; font-size: 14px; }
        .footer { margin-top: 24px; color: #64748b; font-size: 13px; text-align: center; }
        a { color: #fbbf24; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Aktivasi Lisensi</h1>
        <p class="muted">Instalasi SPPG Vendor Management ini perlu lisensi yang valid sebelum aplikasi dapat digunakan.</p>

        @if (session('error'))
            <div class="alert">{{ session('error') }}</div>
        @endif

        <form action="{{ route('license.activate') }}" method="POST">
            @csrf

            <label>System Signature</label>
            <code>{{ $signature }}</code>
            <p class="muted">Kirim signature ini kepada penerbit lisensi. Lisensi terikat pada APP_KEY dan hostname instalasi ini.</p>

            <label for="license_key">License Key</label>
            <textarea id="license_key" name="license_key" required placeholder="Tempel license key di sini...">{{ old('license_key') }}</textarea>
            @error('license_key')
                <div class="error">{{ $message }}</div>
            @enderror

            <button type="submit">Aktifkan Lisensi</button>
        </form>

        <p class="footer">
            <a href="{{ route('license.status') }}">Lihat status lisensi</a>
            · &copy; {{ date('Y') }} SPPG Vendor Management
        </p>
    </main>
</body>
</html>
