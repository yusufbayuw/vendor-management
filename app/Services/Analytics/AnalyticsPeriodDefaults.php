<?php

namespace App\Services\Analytics;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\FulfillmentDiscrepancy;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class AnalyticsPeriodDefaults
{
    public function __construct(private readonly UserAccessService $access) {}

    /** @return array{from: string, to: string} */
    public function purchaseOrders(?User $user): array
    {
        $query = PurchaseOrder::query();

        if ($user !== null) {
            $query->whereIn('sppg_kitchen_id', $this->access->accessibleKitchenIds($user));
        }

        return $this->rangeFromMax($query, 'order_date');
    }

    /** @return array{from: string, to: string} */
    public function goodsReceipts(?User $user): array
    {
        $query = GoodsReceipt::query();

        if ($user !== null) {
            $query->whereIn('sppg_kitchen_id', $this->access->accessibleKitchenIds($user));
        }

        return $this->rangeFromMax($query, 'received_at');
    }

    /** @return array{from: string, to: string} */
    public function invoices(?User $user): array
    {
        $query = Invoice::query();

        if ($user !== null) {
            $query->whereIn('sppg_kitchen_id', $this->access->accessibleKitchenIds($user));
        }

        return $this->rangeFromMax($query, 'invoice_date');
    }

    /** @return array{from: string, to: string} */
    public function discrepancies(?User $user): array
    {
        $query = FulfillmentDiscrepancy::query();

        if ($user !== null) {
            $kitchenIds = $this->access->accessibleKitchenIds($user);
            $query->whereHas(
                'purchaseOrder',
                fn (Builder $orderQuery): Builder => $orderQuery->whereIn('sppg_kitchen_id', $kitchenIds),
            );
        }

        return $this->rangeFromMax($query, 'created_at');
    }

    /** @return array{from: string, to: string} */
    public function approvals(?User $user): array
    {
        $query = ApprovalRequest::query();

        if ($user !== null) {
            $organizationIds = SppgKitchen::query()
                ->whereIn('id', $this->access->accessibleKitchenIds($user))
                ->pluck('organization_id')
                ->unique()
                ->values();

            if ($organizationIds->isNotEmpty()) {
                $query->whereIn('organization_id', $organizationIds);
            }
        }

        return $this->rangeFromMax($query, 'requested_at');
    }

    /** @return array{from: string, to: string} */
    public function audit(): array
    {
        return $this->rangeFromMax(AuditLog::query(), 'occurred_at');
    }

    /** @return array{from: string, to: string} */
    private function rangeFromMax(Builder $query, string $column): array
    {
        $latest = $query->max($column);
        $to = $latest !== null
            ? CarbonImmutable::parse((string) $latest)->endOfDay()
            : CarbonImmutable::today();

        return [
            'from' => $to->subMonthsNoOverflow(3)->startOfDay()->toDateString(),
            'to' => $to->toDateString(),
        ];
    }
}
