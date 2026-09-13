@extends('documents.layout')

@section('title', $goodsReceipt->number)

@section('content')
    <div class="header">
        <div>
            <h1 class="title">BERITA ACARA PENERIMAAN BARANG</h1>
            <div class="muted">Goods Receipt / BAST</div>
        </div>
        <div class="right"><strong>{{ $goodsReceipt->number }}</strong></div>
    </div>

    <table class="meta">
        <tr><td>Organisasi</td><td>{{ $goodsReceipt->kitchen->organization->name }}</td></tr>
        <tr><td>Dapur SPPG</td><td>{{ $goodsReceipt->kitchen->name }}</td></tr>
        <tr><td>Purchase Order</td><td>{{ $goodsReceipt->purchaseOrder->number }}</td></tr>
        <tr><td>Supplier</td><td>{{ $goodsReceipt->supplier->legal_name }}</td></tr>
        <tr><td>Diterima</td><td>{{ $goodsReceipt->received_at?->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Penerima</td><td>{{ $goodsReceipt->receiver?->name ?? '-' }}</td></tr>
        <tr><td>Pemeriksa QC</td><td>{{ $goodsReceipt->inspector?->name ?? '-' }}</td></tr>
        <tr><td>Perwakilan Supplier</td><td>{{ $goodsReceipt->supplier_representative ?: '-' }}</td></tr>
        <tr><td>Surat Jalan</td><td>{{ $goodsReceipt->delivery_note_number ?: '-' }}</td></tr>
        <tr><td>Kendaraan</td><td>{{ $goodsReceipt->vehicle_number ?: '-' }}</td></tr>
        <tr><td>Status</td><td>{{ $goodsReceipt->status->value }}</td></tr>
    </table>

    <table class="items">
        <thead>
        <tr>
            <th>No</th>
            <th>Produk</th>
            <th class="right">Rencana</th>
            <th class="right">Diterima Fisik</th>
            <th class="right">Diterima QC</th>
            <th class="right">Ditolak</th>
            <th>Kondisi / Catatan</th>
        </tr>
        </thead>
        <tbody>
        @foreach($goodsReceipt->items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $item->purchaseOrderItem->product_name_snapshot }}</td>
                <td class="right">{{ number_format((float) $item->planned_qty, 4, ',', '.') }}</td>
                <td class="right">{{ number_format((float) $item->received_qty, 4, ',', '.') }}</td>
                <td class="right">{{ number_format((float) $item->accepted_qty, 4, ',', '.') }}</td>
                <td class="right">{{ number_format((float) $item->rejected_qty, 4, ',', '.') }}</td>
                <td>
                    {{ $item->condition ?: '-' }}
                    @if($item->rejection_reason)<br><span class="muted">Ditolak: {{ $item->rejection_reason }}</span>@endif
                    @if($item->notes)<br><span class="muted">{{ $item->notes }}</span>@endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if($goodsReceipt->discrepancies->isNotEmpty())
        <div class="notes">
            <strong>Discrepancy:</strong>
            <ul>
                @foreach($goodsReceipt->discrepancies as $discrepancy)
                    <li>{{ $discrepancy->type->value ?? $discrepancy->type }} — {{ $discrepancy->description ?: 'Tanpa catatan' }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="notes">
        <strong>Lampiran tersimpan:</strong> {{ $goodsReceipt->attachments->count() }} file.<br>
        <strong>Catatan penerimaan:</strong> {{ $goodsReceipt->notes ?: '-' }}
    </div>

    <div class="signatures">
        <div>Penerima / SPPG<div class="line">{{ $goodsReceipt->receiver?->name ?? 'Nama & Tanda Tangan' }}</div></div>
        <div>Perwakilan Supplier<div class="line">{{ $goodsReceipt->supplier_representative ?: 'Nama & Tanda Tangan' }}</div></div>
    </div>
@endsection
