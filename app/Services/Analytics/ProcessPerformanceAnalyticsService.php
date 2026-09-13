<?php

namespace App\Services\Analytics;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ProcessPerformanceAnalyticsService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function query(User $user): Builder
    {
        return PurchaseOrder::query()
            ->with([
                'supplier',
                'kitchen.organization',
                'purchaseRequest',
                'goodsReceipts',
                'invoice.payments',
            ])
            ->whereIn('sppg_kitchen_id', $this->access->accessibleKitchenIds($user))
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value);
    }

    public function prApprovalHours(PurchaseOrder $order): ?float
    {
        return $this->hours(
            $order->purchaseRequest?->submitted_at,
            $order->purchaseRequest?->approved_at,
        );
    }

    public function poGenerationHours(PurchaseOrder $order): ?float
    {
        return $this->hours(
            $order->purchaseRequest?->approved_at,
            $order->created_at,
        );
    }

    public function poApprovalHours(PurchaseOrder $order): ?float
    {
        return $this->hours($order->created_at, $order->approved_at);
    }

    public function issueHours(PurchaseOrder $order): ?float
    {
        return $this->hours($order->approved_at, $order->issued_at);
    }

    public function acknowledgementHours(PurchaseOrder $order): ?float
    {
        return $this->hours($order->issued_at, $order->acknowledged_at);
    }

    public function firstReceiptHours(PurchaseOrder $order): ?float
    {
        $firstReceipt = $order->goodsReceipts
            ->pluck('received_at')
            ->filter()
            ->min();

        return $this->hours($order->acknowledged_at, $firstReceipt);
    }

    public function averageQcHours(PurchaseOrder $order): ?float
    {
        $durations = $order->goodsReceipts
            ->filter(fn (GoodsReceipt $receipt): bool => $receipt->received_at !== null && $receipt->inspected_at !== null)
            ->map(fn (GoodsReceipt $receipt): float => $this->hours($receipt->received_at, $receipt->inspected_at) ?? 0);

        return $durations->isEmpty() ? null : round((float) $durations->average(), 1);
    }

    public function deliveryQcCycleHours(PurchaseOrder $order): ?float
    {
        $lastInspection = $order->goodsReceipts
            ->pluck('inspected_at')
            ->filter()
            ->max();

        return $this->hours($order->acknowledged_at, $lastInspection);
    }

    public function invoiceApprovalHours(PurchaseOrder $order): ?float
    {
        return $this->hours(
            $order->invoice?->issued_at,
            $order->invoice?->approved_at,
        );
    }

    public function paymentCycleHours(PurchaseOrder $order): ?float
    {
        if ($order->invoice?->approved_at === null) {
            return null;
        }

        $paymentCompletedAt = $order->paid_at;

        if ($paymentCompletedAt === null) {
            $paymentCompletedAt = $order->invoice->payments
                ->pluck('verified_at')
                ->filter()
                ->max();
        }

        return $this->hours($order->invoice->approved_at, $paymentCompletedAt);
    }

    public function endToEndHours(PurchaseOrder $order): ?float
    {
        return $this->hours(
            $order->purchaseRequest?->submitted_at,
            $order->closed_at,
        );
    }

    public function longestCompletedStage(PurchaseOrder $order): string
    {
        $stages = collect([
            'PR Approval' => $this->prApprovalHours($order),
            'PR → PO' => $this->poGenerationHours($order),
            'PO Approval' => $this->poApprovalHours($order),
            'Approval → Issue' => $this->issueHours($order),
            'Supplier Ack' => $this->acknowledgementHours($order),
            'Ack → First Receipt' => $this->firstReceiptHours($order),
            'Delivery/QC Cycle' => $this->deliveryQcCycleHours($order),
            'Invoice Approval' => $this->invoiceApprovalHours($order),
            'Payment Cycle' => $this->paymentCycleHours($order),
        ])->filter(fn (?float $hours): bool => $hours !== null && $hours >= 0);

        if ($stages->isEmpty()) {
            return '-';
        }

        $maximum = (float) $stages->max();
        $stage = (string) $stages->search($maximum, strict: true);

        return sprintf('%s (%s)', $stage, $this->formatHours($maximum));
    }

    public function formatHours(?float $hours): string
    {
        if ($hours === null) {
            return '-';
        }

        if (abs($hours) < 24) {
            return number_format($hours, 1, ',', '.').' jam';
        }

        return number_format($hours / 24, 1, ',', '.').' hari';
    }

    private function hours(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        return round(($to->getTimestamp() - $from->getTimestamp()) / 3600, 1);
    }
}
