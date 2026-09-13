@extends('documents.layout')

@section('title', $purchaseOrder->number)

@section('content')
    <div class="header">
        <div>
            <h1 class="title">PURCHASE ORDER</h1>
            <div class="muted">SPPG Vendor Management</div>
        </div>
        <div class="right">
            <strong>{{ $purchaseOrder->number }}</strong><br>
            <span class="muted">Revisi {{ $purchaseOrder->revision_number }}</span>
        </div>
    </div>

    <table class="meta">
        <tr><td>Organisasi</td><td>{{ $purchaseOrder->kitchen->organization->name }}</td></tr>
        <tr><td>Dapur SPPG</td><td>{{ $purchaseOrder->kitchen->name }}</td></tr>
        <tr><td>Supplier</td><td>{{ $purchaseOrder->supplier->legal_name }} @if($purchaseOrder->supplier->display_name)({{ $purchaseOrder->supplier->display_name }})@endif</td></tr>
        <tr><td>Tanggal PO</td><td>{{ $purchaseOrder->order_date?->format('d/m/Y') }}</td></tr>
        <tr><td>Periode Pengiriman</td><td>{{ $purchaseOrder->delivery_start?->format('d/m/Y') ?? '-' }} s.d. {{ $purchaseOrder->delivery_end?->format('d/m/Y') ?? '-' }}</td></tr>
        <tr><td>Status</td><td>{{ $purchaseOrder->status->value }}</td></tr>
    </table>

    <table class="items">
        <thead>
        <tr>
            <th>No</th>
            <th>Produk / Spesifikasi</th>
            <th class="right">Qty</th>
            <th>Satuan</th>
            <th class="right">Harga Satuan</th>
            <th class="right">Subtotal</th>
        </tr>
        </thead>
        <tbody>
        @foreach($purchaseOrder->items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td><strong>{{ $item->product_name_snapshot }}</strong><br><span class="muted">{{ $item->description_snapshot }}</span></td>
                <td class="right">{{ number_format((float) $item->ordered_qty, 4, ',', '.') }}</td>
                <td>{{ $item->unit_name_snapshot }}</td>
                <td class="right">Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}</td>
                <td class="right">Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="summary">
        <div><span>Subtotal</span><span>Rp {{ number_format((float) $purchaseOrder->subtotal, 0, ',', '.') }}</span></div>
        <div><span>Pajak</span><span>Rp {{ number_format((float) $purchaseOrder->tax_amount, 0, ',', '.') }}</span></div>
        <div><span>Diskon</span><span>Rp {{ number_format((float) $purchaseOrder->discount_amount, 0, ',', '.') }}</span></div>
        <div class="total"><span>Total PO</span><span>Rp {{ number_format((float) $purchaseOrder->total_amount, 0, ',', '.') }}</span></div>
    </div>

    <div class="notes"><strong>Catatan:</strong><br>{{ $purchaseOrder->notes ?: '-' }}</div>

    <div class="signatures">
        <div>SPPG / Procurement<div class="line">Nama & Tanda Tangan</div></div>
        <div>Supplier<div class="line">Nama & Tanda Tangan</div></div>
    </div>
@endsection
