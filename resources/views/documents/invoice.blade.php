@extends('documents.layout')

@section('title', $invoice->number)

@section('content')
    <div class="header">
        <div>
            <h1 class="title">INVOICE</h1>
            <div class="muted">SPPG Vendor Management</div>
        </div>
        <div class="right">
            <strong>{{ $invoice->number }}</strong><br>
            <span class="muted">Supplier: {{ $invoice->supplier_invoice_number ?: '-' }}</span>
        </div>
    </div>

    <table class="meta">
        <tr><td>Organisasi</td><td>{{ $invoice->kitchen->organization->name }}</td></tr>
        <tr><td>Dapur SPPG</td><td>{{ $invoice->kitchen->name }}</td></tr>
        <tr><td>Supplier</td><td>{{ $invoice->supplier->legal_name }}</td></tr>
        <tr><td>Purchase Order</td><td>{{ $invoice->purchaseOrder->number }}</td></tr>
        <tr><td>Tanggal Invoice</td><td>{{ $invoice->invoice_date?->format('d/m/Y') }}</td></tr>
        <tr><td>Jatuh Tempo</td><td>{{ $invoice->due_date?->format('d/m/Y') ?? '-' }}</td></tr>
        <tr><td>Status</td><td>{{ $invoice->status->value }}</td></tr>
    </table>

    <table class="items">
        <thead>
        <tr>
            <th>No</th>
            <th>Produk</th>
            <th class="right">Qty PO</th>
            <th class="right">Harga Satuan</th>
            <th class="right">Subtotal PO</th>
        </tr>
        </thead>
        <tbody>
        @foreach($invoice->purchaseOrder->items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $item->product_name_snapshot }}</td>
                <td class="right">{{ number_format((float) $item->ordered_qty, 4, ',', '.') }} {{ $item->unit_name_snapshot }}</td>
                <td class="right">Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}</td>
                <td class="right">Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="summary">
        <div><span>Nilai dasar PO</span><span>Rp {{ number_format((float) $invoice->po_amount, 0, ',', '.') }}</span></div>
        <div><span>Adjustment disetujui</span><span>Rp {{ number_format((float) $invoice->adjustment_amount, 0, ',', '.') }}</span></div>
        <div><span>Pajak dipotong</span><span>Rp {{ number_format((float) $invoice->withholding_tax_amount, 0, ',', '.') }}</span></div>
        <div class="total"><span>Payable</span><span>Rp {{ number_format((float) $invoice->payable_amount, 0, ',', '.') }}</span></div>
    </div>

    @if($invoice->adjustments->isNotEmpty())
        <div class="notes">
            <strong>Adjustment:</strong>
            <ul>
                @foreach($invoice->adjustments as $adjustment)
                    <li>{{ $adjustment->description }} — Rp {{ number_format((float) $adjustment->amount, 0, ',', '.') }} ({{ $adjustment->status->value ?? $adjustment->status }})</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="notes">
        <strong>Catatan:</strong> {{ $invoice->notes ?: '-' }}<br>
        <span class="muted">Nilai dasar invoice mengikuti nilai Purchase Order. Selisih fulfillment dicatat terpisah melalui discrepancy/adjustment yang disetujui.</span>
    </div>

    <div class="signatures">
        <div>Supplier<div class="line">Nama & Tanda Tangan</div></div>
        <div>Finance / SPPG<div class="line">Nama & Tanda Tangan</div></div>
    </div>
@endsection
