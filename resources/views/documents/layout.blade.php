<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - SPPG Vendor Management</title>
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #111827; }
        body { margin: 0; background: #f3f4f6; }
        .toolbar { max-width: 980px; margin: 20px auto 0; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar button { border: 0; border-radius: 8px; background: #111827; color: white; padding: 9px 14px; cursor: pointer; }
        .sheet { max-width: 900px; margin: 12px auto 40px; background: white; padding: 36px; box-shadow: 0 1px 8px rgba(0,0,0,.08); }
        .header { display: flex; justify-content: space-between; gap: 30px; border-bottom: 2px solid #111827; padding-bottom: 18px; margin-bottom: 24px; }
        .title { font-size: 26px; font-weight: 700; margin: 0; }
        .muted { color: #6b7280; font-size: 12px; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .meta td { padding: 4px 8px 4px 0; vertical-align: top; }
        .meta td:first-child { width: 180px; color: #4b5563; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.items th, table.items td { border: 1px solid #d1d5db; padding: 8px; text-align: left; vertical-align: top; }
        table.items th { background: #f9fafb; font-size: 12px; }
        .right { text-align: right !important; }
        .summary { margin-left: auto; width: 380px; margin-top: 18px; }
        .summary div { display: flex; justify-content: space-between; padding: 5px 0; }
        .summary .total { border-top: 2px solid #111827; margin-top: 6px; padding-top: 10px; font-weight: 700; }
        .notes { margin-top: 24px; padding: 14px; border: 1px solid #d1d5db; min-height: 42px; }
        .signatures { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 70px; margin-top: 48px; text-align: center; }
        .signatures .line { border-top: 1px solid #111827; margin-top: 64px; padding-top: 5px; }
        @media print {
            body { background: white; }
            .toolbar { display: none; }
            .sheet { max-width: none; margin: 0; padding: 16mm; box-shadow: none; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Cetak / Simpan PDF</button></div>
<main class="sheet">
    @yield('content')
</main>
</body>
</html>
