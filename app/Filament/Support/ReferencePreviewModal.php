<?php

namespace App\Filament\Support;

use App\Models\DeliverySchedule;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

class ReferencePreviewModal
{
    public static function purchaseRequest(string $name, Closure $resolver): Action
    {
        return self::make(
            $name,
            'Purchase Request',
            $resolver,
            static function (PurchaseRequest $request): array {
                $request->loadMissing(['kitchen', 'requester', 'items.product', 'items.unit']);

                return [
                    'Nomor' => $request->number,
                    'SPPG' => $request->kitchen?->name,
                    'Pemohon' => $request->requester?->name,
                    'Periode' => self::dateRange($request->period_start, $request->period_end),
                    'Status' => self::status($request->status),
                    'Deskripsi' => $request->description,
                ];
            },
            static function (PurchaseRequest $request): array {
                return $request->items->map(static fn ($item): array => [
                    'Produk' => $item->product?->name ?? $item->description ?? '-',
                    'Jumlah' => self::quantity($item->requested_qty),
                    'Satuan' => $item->unit?->symbol ?: ($item->unit?->name ?? '-'),
                    'Estimasi' => self::money($item->estimated_total),
                ])->all();
            },
            static fn (PurchaseRequest $request): string => route('filament.admin.resources.purchase-requests.view', ['record' => $request]),
        );
    }

    public static function purchaseOrder(string $name, Closure $resolver): Action
    {
        return self::make(
            $name,
            'Purchase Order',
            $resolver,
            static function (PurchaseOrder $order): array {
                $order->loadMissing(['supplier', 'kitchen', 'purchaseRequest', 'items']);

                return [
                    'Nomor' => $order->number,
                    'PR' => $order->purchaseRequest?->number,
                    'Supplier' => $order->supplier?->display_name ?: $order->supplier?->legal_name,
                    'SPPG' => $order->kitchen?->name,
                    'Tanggal PO' => $order->order_date?->format('d/m/Y'),
                    'Status' => self::status($order->status),
                    'Total' => self::money($order->total_amount),
                ];
            },
            static function (PurchaseOrder $order): array {
                return $order->items->map(static fn ($item): array => [
                    'Produk' => $item->product_name_snapshot,
                    'Jumlah' => self::quantity($item->ordered_qty),
                    'Satuan' => $item->unit_name_snapshot,
                    'Harga' => self::money($item->unit_price),
                    'Subtotal' => self::money($item->subtotal),
                ])->all();
            },
            static fn (PurchaseOrder $order): string => route('filament.admin.resources.purchase-orders.view', ['record' => $order]),
        );
    }

    public static function deliverySchedule(string $name, Closure $resolver): Action
    {
        return self::make(
            $name,
            'Jadwal Pengiriman',
            $resolver,
            static function (DeliverySchedule $schedule): array {
                $schedule->loadMissing(['purchaseOrder.supplier', 'purchaseOrder.kitchen', 'items.purchaseOrderItem']);

                return [
                    'Nomor' => $schedule->number,
                    'PO' => $schedule->purchaseOrder?->number,
                    'Supplier' => $schedule->purchaseOrder?->supplier?->display_name ?: $schedule->purchaseOrder?->supplier?->legal_name,
                    'SPPG' => $schedule->purchaseOrder?->kitchen?->name,
                    'Jadwal' => $schedule->planned_delivery_at?->format('d/m/Y H:i'),
                    'Estimasi tiba' => $schedule->estimated_arrival_at?->format('d/m/Y H:i'),
                    'Status' => self::status($schedule->status),
                    'Kendaraan' => $schedule->vehicle_number,
                ];
            },
            static function (DeliverySchedule $schedule): array {
                return $schedule->items->map(static fn ($item): array => [
                    'Produk' => $item->purchaseOrderItem?->product_name_snapshot ?? '-',
                    'Jumlah' => self::quantity($item->planned_qty),
                    'Satuan' => $item->purchaseOrderItem?->unit_name_snapshot ?? '-',
                ])->all();
            },
        );
    }

    public static function invoice(string $name, Closure $resolver): Action
    {
        return self::make(
            $name,
            'Invoice',
            $resolver,
            static function (Invoice $invoice): array {
                $invoice->loadMissing(['purchaseOrder', 'supplier', 'kitchen']);
                $invoice->loadCount('payments');

                return [
                    'Nomor' => $invoice->number,
                    'Nomor Supplier' => $invoice->supplier_invoice_number,
                    'PO' => $invoice->purchaseOrder?->number,
                    'Supplier' => $invoice->supplier?->display_name ?: $invoice->supplier?->legal_name,
                    'SPPG' => $invoice->kitchen?->name,
                    'Tanggal' => $invoice->invoice_date?->format('d/m/Y'),
                    'Jatuh tempo' => $invoice->due_date?->format('d/m/Y'),
                    'Status' => self::status($invoice->status),
                    'Payable' => self::money($invoice->payable_amount),
                    'Pembayaran' => (string) $invoice->payments_count,
                ];
            },
        );
    }

    private static function make(
        string $name,
        string $type,
        Closure $resolver,
        Closure $fields,
        ?Closure $items = null,
        ?Closure $url = null,
    ): Action {
        return Action::make($name)
            ->modalHeading(function (Model $record) use ($resolver, $type): string {
                $reference = $resolver($record);

                return $reference instanceof Model
                    ? $type.' · '.($reference->getAttribute('number') ?? '#'.$reference->getKey())
                    : $type;
            })
            ->modalWidth('5xl')
            ->modalContent(function (Model $record) use ($resolver, $fields, $items, $url, $type) {
                $reference = $resolver($record);

                if (! $reference instanceof Model) {
                    return view('filament.components.reference-preview', [
                        'type' => $type,
                        'fields' => [],
                        'items' => [],
                        'url' => null,
                        'missing' => true,
                    ]);
                }

                return view('filament.components.reference-preview', [
                    'type' => $type,
                    'fields' => $fields($reference),
                    'items' => $items ? $items($reference) : [],
                    'url' => $url ? $url($reference) : null,
                    'missing' => false,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->visible(fn (Model $record): bool => $resolver($record) instanceof Model);
    }

    private static function status(mixed $status): string
    {
        $value = $status instanceof BackedEnum ? $status->value : (string) $status;

        return str($value)->replace('_', ' ')->title()->toString();
    }

    private static function money(mixed $value): string
    {
        return $value === null ? '-' : 'Rp '.number_format((float) $value, 0, ',', '.');
    }

    private static function quantity(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        return rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    }

    private static function dateRange(mixed $from, mixed $to): string
    {
        $fromLabel = $from?->format('d/m/Y') ?? '-';
        $toLabel = $to?->format('d/m/Y') ?? '-';

        return $fromLabel.' – '.$toLabel;
    }
}
