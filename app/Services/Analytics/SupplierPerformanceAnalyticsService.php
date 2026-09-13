<?php

namespace App\Services\Analytics;

use App\Enums\PurchaseOrderStatus;
use App\Models\DeliverySchedule;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SupplierPerformanceAnalyticsService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function query(User $user): Builder
    {
        return PurchaseOrder::query()
            ->with([
                'supplier',
                'kitchen',
                'items',
                'deliverySchedules.goodsReceipts',
                'deliverySchedules.items.goodsReceiptItems',
            ])
            ->withCount('discrepancies')
            ->whereIn('sppg_kitchen_id', $this->access->accessibleKitchenIds($user))
            ->whereNotIn('status', [
                PurchaseOrderStatus::Draft->value,
                PurchaseOrderStatus::PendingApproval->value,
                PurchaseOrderStatus::Approved->value,
                PurchaseOrderStatus::Cancelled->value,
            ]);
    }

    public function fillRate(PurchaseOrder $order): ?float
    {
        $ratios = $order->items
            ->filter(fn (PurchaseOrderItem $item): bool => (float) $item->ordered_qty > 0)
            ->map(fn (PurchaseOrderItem $item): float => min(
                1,
                (float) $item->accepted_qty / (float) $item->ordered_qty,
            ));

        return $this->percentageAverage($ratios);
    }

    public function rejectRate(PurchaseOrder $order): ?float
    {
        $ratios = $order->items
            ->filter(fn (PurchaseOrderItem $item): bool => (float) $item->delivered_qty > 0)
            ->map(fn (PurchaseOrderItem $item): float => min(
                1,
                (float) $item->rejected_qty / (float) $item->delivered_qty,
            ));

        return $this->percentageAverage($ratios);
    }

    public function onTimeRate(PurchaseOrder $order): ?float
    {
        $schedules = $this->evaluatedSchedules($order);

        if ($schedules->isEmpty()) {
            return null;
        }

        return 100 * $schedules->filter(fn (DeliverySchedule $schedule): bool => $this->isOnTime($schedule))->count() / $schedules->count();
    }

    public function inFullRate(PurchaseOrder $order): ?float
    {
        $schedules = $this->evaluatedSchedules($order);

        if ($schedules->isEmpty()) {
            return null;
        }

        return 100 * $schedules->filter(fn (DeliverySchedule $schedule): bool => $this->isInFull($schedule))->count() / $schedules->count();
    }

    public function otifRate(PurchaseOrder $order): ?float
    {
        $schedules = $this->evaluatedSchedules($order);

        if ($schedules->isEmpty()) {
            return null;
        }

        return 100 * $schedules
            ->filter(fn (DeliverySchedule $schedule): bool => $this->isOnTime($schedule) && $this->isInFull($schedule))
            ->count() / $schedules->count();
    }

    public function evaluatedDeliveryCount(PurchaseOrder $order): int
    {
        return $this->evaluatedSchedules($order)->count();
    }

    /** @return Collection<int, DeliverySchedule> */
    private function evaluatedSchedules(PurchaseOrder $order): Collection
    {
        return $order->deliverySchedules
            ->filter(fn (DeliverySchedule $schedule): bool => $schedule->goodsReceipts->isNotEmpty())
            ->values();
    }

    private function isOnTime(DeliverySchedule $schedule): bool
    {
        if ($schedule->planned_delivery_at === null || $schedule->goodsReceipts->isEmpty()) {
            return false;
        }

        $latestReceipt = $schedule->goodsReceipts
            ->pluck('received_at')
            ->filter()
            ->max();

        return $latestReceipt !== null && $latestReceipt->lessThanOrEqualTo($schedule->planned_delivery_at);
    }

    private function isInFull(DeliverySchedule $schedule): bool
    {
        if ($schedule->items->isEmpty()) {
            return false;
        }

        return $schedule->items->every(function ($scheduleItem): bool {
            $received = (float) $scheduleItem->goodsReceiptItems->sum('received_qty');

            return $received + 0.0001 >= (float) $scheduleItem->planned_qty;
        });
    }

    /** @param Collection<int, float> $ratios */
    private function percentageAverage(Collection $ratios): ?float
    {
        if ($ratios->isEmpty()) {
            return null;
        }

        return 100 * (float) $ratios->average();
    }
}
