<?php

namespace App\Services\Analytics;

use App\Enums\DiscrepancyStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;

class SppgAnalyticsService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function query(User $user): Builder
    {
        return PurchaseOrder::query()
            ->with([
                'kitchen.organization',
                'supplier',
                'invoice.payments',
            ])
            ->withCount('items')
            ->withCount([
                'discrepancies as open_discrepancies_count' => fn (Builder $query): Builder => $query
                    ->where('status', DiscrepancyStatus::Open->value),
            ])
            ->whereIn('sppg_kitchen_id', $this->access->accessibleKitchenIds($user));
    }
}
