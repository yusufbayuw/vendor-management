<?php

namespace App\Services\Analytics;

use App\Models\FulfillmentDiscrepancy;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;

class DiscrepancyAnalyticsService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function query(User $user): Builder
    {
        return FulfillmentDiscrepancy::query()
            ->with([
                'purchaseOrder.kitchen',
                'purchaseOrder.supplier',
                'purchaseOrderItem.product.category',
                'purchaseOrderItem.unit',
                'goodsReceipt',
                'approver',
            ])
            ->whereHas(
                'purchaseOrder',
                fn (Builder $query): Builder => $query->whereIn(
                    'sppg_kitchen_id',
                    $this->access->accessibleKitchenIds($user),
                ),
            );
    }

    public function varianceRate(FulfillmentDiscrepancy $discrepancy): ?float
    {
        $expected = (float) $discrepancy->expected_qty;

        return $expected > 0
            ? 100 * (float) $discrepancy->variance_qty / $expected
            : null;
    }

    public function resolutionHours(FulfillmentDiscrepancy $discrepancy): ?float
    {
        if ($discrepancy->resolved_at === null || $discrepancy->created_at === null) {
            return null;
        }

        return round($discrepancy->created_at->diffInMinutes($discrepancy->resolved_at) / 60, 1);
    }
}
