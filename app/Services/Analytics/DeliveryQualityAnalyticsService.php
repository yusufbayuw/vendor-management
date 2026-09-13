<?php

namespace App\Services\Analytics;

use App\Models\GoodsReceiptItem;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;

class DeliveryQualityAnalyticsService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function query(User $user): Builder
    {
        return GoodsReceiptItem::query()
            ->with([
                'goodsReceipt.kitchen',
                'goodsReceipt.supplier',
                'goodsReceipt.deliverySchedule',
                'purchaseOrderItem.product.category',
                'purchaseOrderItem.unit',
            ])
            ->whereHas(
                'goodsReceipt',
                fn (Builder $query): Builder => $query->whereIn(
                    'sppg_kitchen_id',
                    $this->access->accessibleKitchenIds($user),
                ),
            );
    }

    public function acceptanceRate(GoodsReceiptItem $item): ?float
    {
        $received = (float) $item->received_qty;

        return $received > 0
            ? 100 * min(1, (float) $item->accepted_qty / $received)
            : null;
    }

    public function rejectRate(GoodsReceiptItem $item): ?float
    {
        $received = (float) $item->received_qty;

        return $received > 0
            ? 100 * min(1, (float) $item->rejected_qty / $received)
            : null;
    }

    public function varianceRate(GoodsReceiptItem $item): ?float
    {
        $planned = (float) $item->planned_qty;

        return $planned > 0
            ? 100 * (float) $item->variance_qty / $planned
            : null;
    }
}
