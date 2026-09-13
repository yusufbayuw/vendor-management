<x-filament-panels::page>
    @php($summary = $this->summary())

    <form method="GET" class="mb-6 grid gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 md:grid-cols-4">
        <label class="flex flex-col gap-1 text-sm">
            <span>Dari tanggal</span>
            <input type="date" name="from" value="{{ $this->fromDate() }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950" />
        </label>
        <label class="flex flex-col gap-1 text-sm">
            <span>Sampai tanggal</span>
            <input type="date" name="to" value="{{ $this->toDate() }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950" />
        </label>
        <div class="flex items-end">
            <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500">
                Terapkan
            </button>
        </div>
        <div class="flex items-end md:justify-end">
            <a href="{{ $this->csvUrl() }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold dark:border-gray-700">
                Export CSV
            </a>
        </div>
    </form>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Jumlah PO', 'value' => number_format($summary['po_count'], 0, ',', '.')],
            ['label' => 'Nilai PO', 'value' => 'Rp '.number_format($summary['po_value'], 0, ',', '.')],
            ['label' => 'Supplier Aktif pada PO', 'value' => number_format($summary['supplier_count'], 0, ',', '.')],
            ['label' => 'Discrepancy Terbuka', 'value' => number_format($summary['open_discrepancy_count'], 0, ',', '.')],
            ['label' => 'Jumlah Invoice', 'value' => number_format($summary['invoice_count'], 0, ',', '.')],
            ['label' => 'Nilai Invoice', 'value' => 'Rp '.number_format($summary['invoice_value'], 0, ',', '.')],
            ['label' => 'Pembayaran Terverifikasi', 'value' => 'Rp '.number_format($summary['verified_payment_value'], 0, ',', '.')],
            ['label' => 'Outstanding', 'value' => 'Rp '.number_format($summary['outstanding_value'], 0, ',', '.')],
        ] as $card)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight">{{ $card['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 rounded-xl bg-white p-5 text-sm text-gray-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
        Laporan mengikuti scope akses user. User SPPG hanya melihat transaksi dapur yang ditugaskan, sedangkan user global melihat seluruh SPPG.
    </div>
</x-filament-panels::page>
