<?php

namespace App\Services\Analytics;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\DiscrepancyStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OperationalRiskAnalyticsService
{
    private const SEVERITY_RANK = [
        'normal' => 0,
        'medium' => 1,
        'high' => 2,
        'critical' => 3,
    ];

    public function __construct(
        private readonly UserAccessService $access,
        private readonly SupplierPerformanceAnalyticsService $supplierPerformance,
    ) {}

    public function query(User $user): Builder
    {
        return PurchaseOrder::query()
            ->with([
                'supplier',
                'kitchen',
                'items',
                'deliverySchedules.goodsReceipts',
                'discrepancies',
                'invoice.payments',
            ])
            ->withCount([
                'discrepancies as open_discrepancies_count' => fn (Builder $query): Builder => $query
                    ->where('status', DiscrepancyStatus::Open->value),
                'deliverySchedules as overdue_schedules_count' => fn (Builder $query): Builder => $query
                    ->where('planned_delivery_at', '<', now())
                    ->where('status', '!=', DeliveryScheduleStatus::Cancelled->value)
                    ->whereDoesntHave('goodsReceipts'),
            ])
            ->whereIn('sppg_kitchen_id', $this->access->accessibleKitchenIds($user))
            ->whereNotIn('status', [
                PurchaseOrderStatus::Draft->value,
                PurchaseOrderStatus::Cancelled->value,
            ]);
    }

    public function applyNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $riskQuery): void {
            $riskQuery
                ->where(function (Builder $ackQuery): void {
                    $ackQuery
                        ->where('status', PurchaseOrderStatus::Issued->value)
                        ->whereNull('acknowledged_at');
                })
                ->orWhereIn('status', [
                    PurchaseOrderStatus::PendingExceptionClosure->value,
                    PurchaseOrderStatus::ClosedWithException->value,
                ])
                ->orWhere(function (Builder $supplierQuery): void {
                    $supplierQuery
                        ->whereIn('status', $this->activeFulfillmentStatuses())
                        ->whereHas('supplier', fn (Builder $query): Builder => $query->whereIn('status', [
                            SupplierStatus::Suspended->value,
                            SupplierStatus::Inactive->value,
                            SupplierStatus::Rejected->value,
                        ]));
                })
                ->orWhereHas('deliverySchedules', fn (Builder $query): Builder => $query
                    ->where('planned_delivery_at', '<', now())
                    ->where('status', '!=', DeliveryScheduleStatus::Cancelled->value)
                    ->whereDoesntHave('goodsReceipts'))
                ->orWhereHas('discrepancies', fn (Builder $query): Builder => $query
                    ->where('status', DiscrepancyStatus::Open->value))
                ->orWhereHas('items', fn (Builder $query): Builder => $query->where('rejected_qty', '>', 0))
                ->orWhere(function (Builder $deliveryQuery): void {
                    $deliveryQuery
                        ->whereDate('delivery_end', '<', today())
                        ->whereIn('status', $this->activeFulfillmentStatuses())
                        ->whereHas('items', fn (Builder $query): Builder => $query
                            ->whereColumn('accepted_qty', '<', 'ordered_qty'));
                })
                ->orWhereHas('invoice', fn (Builder $query): Builder => $query
                    ->whereDate('due_date', '<', today())
                    ->whereNotIn('status', [
                        InvoiceStatus::Paid->value,
                        InvoiceStatus::Rejected->value,
                        InvoiceStatus::Cancelled->value,
                    ]));
        });
    }

    /**
     * @return Collection<int, array{severity: string, code: string, label: string}>
     */
    public function riskFlags(PurchaseOrder $order): Collection
    {
        $flags = collect();

        if ($this->hasSupplierAvailabilityRisk($order)) {
            $flags->push($this->flag(
                'critical',
                'supplier_unavailable',
                'Supplier tidak aktif untuk PO yang masih dalam proses fulfillment.',
            ));
        }

        $overdueInvoiceDays = $this->invoiceOverdueDays($order);
        if ($overdueInvoiceDays !== null) {
            $flags->push($this->flag(
                'critical',
                'invoice_overdue',
                'Invoice lewat jatuh tempo '.$overdueInvoiceDays.' hari dengan saldo belum terbayar.',
            ));
        }

        $overdueSchedules = $this->overdueScheduleCount($order);
        if ($overdueSchedules > 0) {
            $flags->push($this->flag(
                'high',
                'delivery_overdue',
                $overdueSchedules.' jadwal pengiriman sudah lewat waktu tanpa goods receipt.',
            ));
        }

        $openDiscrepancies = $this->openDiscrepancyCount($order);
        if ($openDiscrepancies > 0) {
            $flags->push($this->flag(
                'high',
                'open_discrepancy',
                $openDiscrepancies.' discrepancy fulfillment masih terbuka.',
            ));
        }

        if ($order->status === PurchaseOrderStatus::PendingExceptionClosure) {
            $flags->push($this->flag(
                'high',
                'exception_pending',
                'PO sedang menunggu keputusan close-with-exception.',
            ));
        }

        if ($this->isDeliveryPastDueAndUnderfilled($order)) {
            $flags->push($this->flag(
                'high',
                'delivery_underfilled',
                'Batas akhir delivery sudah lewat tetapi accepted quantity belum penuh.',
            ));
        }

        if ($order->status === PurchaseOrderStatus::Issued && $order->acknowledged_at === null) {
            $flags->push($this->flag(
                'medium',
                'supplier_ack_pending',
                'PO sudah diterbitkan tetapi belum di-acknowledge supplier.',
            ));
        }

        if ($order->status === PurchaseOrderStatus::ClosedWithException) {
            $flags->push($this->flag(
                'medium',
                'closed_with_exception',
                'PO ditutup dengan exception dan perlu diperhitungkan pada evaluasi supplier.',
            ));
        }

        $rejectRate = $this->rejectRate($order);
        if ($rejectRate !== null && $rejectRate > 0) {
            $flags->push($this->flag(
                'medium',
                'qc_rejection',
                'Terdapat quantity yang ditolak QC (reject rate '.number_format($rejectRate, 1, ',', '.').'%).',
            ));
        }

        return $flags->values();
    }

    public function riskLevel(PurchaseOrder $order): string
    {
        return $this->riskFlags($order)
            ->pluck('severity')
            ->sortByDesc(fn (string $severity): int => self::SEVERITY_RANK[$severity] ?? 0)
            ->first() ?? 'normal';
    }

    public function riskFlagCount(PurchaseOrder $order): int
    {
        return $this->riskFlags($order)->count();
    }

    public function riskReasons(PurchaseOrder $order): string
    {
        $labels = $this->riskFlags($order)->pluck('label');

        return $labels->isEmpty() ? 'Tidak ada risk flag aktif.' : $labels->implode(' | ');
    }

    public function overdueScheduleCount(PurchaseOrder $order): int
    {
        $count = $order->getAttribute('overdue_schedules_count');

        if ($count !== null) {
            return (int) $count;
        }

        return $order->deliverySchedules
            ->filter(fn ($schedule): bool => $schedule->planned_delivery_at !== null
                && $schedule->planned_delivery_at->isPast()
                && $schedule->status !== DeliveryScheduleStatus::Cancelled
                && $schedule->goodsReceipts->isEmpty())
            ->count();
    }

    public function openDiscrepancyCount(PurchaseOrder $order): int
    {
        $count = $order->getAttribute('open_discrepancies_count');

        if ($count !== null) {
            return (int) $count;
        }

        return $order->discrepancies
            ->where('status', DiscrepancyStatus::Open)
            ->count();
    }

    public function fillRate(PurchaseOrder $order): ?float
    {
        return $this->supplierPerformance->fillRate($order);
    }

    public function rejectRate(PurchaseOrder $order): ?float
    {
        return $this->supplierPerformance->rejectRate($order);
    }

    public function invoiceOutstanding(PurchaseOrder $order): ?float
    {
        $invoice = $order->invoice;

        if ($invoice === null || in_array($invoice->status, [
            InvoiceStatus::Rejected,
            InvoiceStatus::Cancelled,
        ], true)) {
            return null;
        }

        $verifiedPayments = $invoice->payments
            ->where('status', PaymentStatus::Verified)
            ->sum(fn ($payment): float => (float) $payment->amount);

        return max(0, (float) $invoice->payable_amount - (float) $verifiedPayments);
    }

    public function invoiceOverdueDays(PurchaseOrder $order): ?int
    {
        $invoice = $order->invoice;
        $outstanding = $this->invoiceOutstanding($order);

        if ($invoice === null
            || $invoice->due_date === null
            || $outstanding === null
            || $outstanding <= 0
            || ! $invoice->due_date->isBefore(today())) {
            return null;
        }

        return (int) $invoice->due_date->diffInDays(today());
    }

    private function hasSupplierAvailabilityRisk(PurchaseOrder $order): bool
    {
        return in_array($order->status, $this->activeFulfillmentStatusEnums(), true)
            && in_array($order->supplier?->status, [
                SupplierStatus::Suspended,
                SupplierStatus::Inactive,
                SupplierStatus::Rejected,
            ], true);
    }

    private function isDeliveryPastDueAndUnderfilled(PurchaseOrder $order): bool
    {
        if ($order->delivery_end === null
            || ! $order->delivery_end->isBefore(today())
            || ! in_array($order->status, $this->activeFulfillmentStatusEnums(), true)) {
            return false;
        }

        return $order->items->contains(
            fn ($item): bool => (float) $item->accepted_qty + 0.0001 < (float) $item->ordered_qty,
        );
    }

    /** @return array{severity: string, code: string, label: string} */
    private function flag(string $severity, string $code, string $label): array
    {
        return compact('severity', 'code', 'label');
    }

    /** @return array<string> */
    private function activeFulfillmentStatuses(): array
    {
        return array_map(
            fn (PurchaseOrderStatus $status): string => $status->value,
            $this->activeFulfillmentStatusEnums(),
        );
    }

    /** @return array<PurchaseOrderStatus> */
    private function activeFulfillmentStatusEnums(): array
    {
        return [
            PurchaseOrderStatus::Issued,
            PurchaseOrderStatus::Acknowledged,
            PurchaseOrderStatus::Scheduled,
            PurchaseOrderStatus::PartiallyDelivered,
            PurchaseOrderStatus::PendingExceptionClosure,
        ];
    }
}
