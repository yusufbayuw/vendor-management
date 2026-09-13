<?php

namespace App\Services\Analytics;

use App\Enums\AccessScopeType;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\DeliverySchedule;
use App\Models\FulfillmentDiscrepancy;
use App\Models\GoodsReceipt;
use App\Models\GovernancePolicy;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Payment;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AuditAnalyticsService
{
    public function __construct(
        private readonly UserAccessService $access,
        private readonly GovernanceAnalyticsService $governanceAnalytics,
    ) {}

    public function query(User $user): Builder
    {
        $query = AuditLog::query()->with(['actor', 'auditable']);

        if ($this->access->hasGlobalAccess($user)) {
            return $query;
        }

        $kitchenIds = $this->access->accessibleKitchenIds($user);
        $organizationIds = $this->access->scopeIds($user, AccessScopeType::Organization);

        return $query->where(function (Builder $scopeQuery) use ($user, $kitchenIds, $organizationIds): void {
            $hasScope = false;

            if ($organizationIds->isNotEmpty()) {
                $this->orModelIds(
                    $scopeQuery,
                    GovernancePolicy::class,
                    GovernancePolicy::query()->whereIn('organization_id', $organizationIds)->select('id'),
                );
                $hasScope = true;
            }

            if ($kitchenIds->isNotEmpty()) {
                $this->orModelIds(
                    $scopeQuery,
                    PurchaseRequest::class,
                    PurchaseRequest::query()->whereIn('sppg_kitchen_id', $kitchenIds)->select('id'),
                );
                $this->orModelIds(
                    $scopeQuery,
                    PurchaseOrder::class,
                    PurchaseOrder::query()->whereIn('sppg_kitchen_id', $kitchenIds)->select('id'),
                );
                $this->orModelIds(
                    $scopeQuery,
                    DeliverySchedule::class,
                    DeliverySchedule::query()
                        ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query->whereIn('sppg_kitchen_id', $kitchenIds))
                        ->select('id'),
                );
                $this->orModelIds(
                    $scopeQuery,
                    GoodsReceipt::class,
                    GoodsReceipt::query()->whereIn('sppg_kitchen_id', $kitchenIds)->select('id'),
                );
                $this->orModelIds(
                    $scopeQuery,
                    FulfillmentDiscrepancy::class,
                    FulfillmentDiscrepancy::query()
                        ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query->whereIn('sppg_kitchen_id', $kitchenIds))
                        ->select('id'),
                );
                $this->orModelIds(
                    $scopeQuery,
                    PurchaseAllocation::class,
                    PurchaseAllocation::query()
                        ->whereHas(
                            'purchaseRequestItem.purchaseRequest',
                            fn (Builder $query): Builder => $query->whereIn('sppg_kitchen_id', $kitchenIds),
                        )
                        ->select('id'),
                );
                $this->orModelIds(
                    $scopeQuery,
                    Invoice::class,
                    Invoice::query()->whereIn('sppg_kitchen_id', $kitchenIds)->select('id'),
                );
                $this->orModelIds(
                    $scopeQuery,
                    InvoiceAdjustment::class,
                    InvoiceAdjustment::query()
                        ->whereHas('invoice', fn (Builder $query): Builder => $query->whereIn('sppg_kitchen_id', $kitchenIds))
                        ->select('id'),
                );
                $this->orModelIds(
                    $scopeQuery,
                    Payment::class,
                    Payment::query()
                        ->whereHas('invoice', fn (Builder $query): Builder => $query->whereIn('sppg_kitchen_id', $kitchenIds))
                        ->select('id'),
                );

                $approvalIds = $this->governanceAnalytics
                    ->query($user)
                    ->select('approval_requests.id');

                $this->orModelIds($scopeQuery, ApprovalRequest::class, $approvalIds);
                $this->orModelIds(
                    $scopeQuery,
                    ApprovalAction::class,
                    ApprovalAction::query()
                        ->whereIn(
                            'approval_request_id',
                            $this->governanceAnalytics
                                ->query($user)
                                ->select('approval_requests.id'),
                        )
                        ->select('id'),
                );

                $hasScope = true;
            }

            if (! $hasScope) {
                $scopeQuery->whereRaw('1 = 0');
            }
        });
    }

    /** @return array<string, string> */
    public function typeOptions(User $user): array
    {
        $types = $this->query($user)
            ->reorder()
            ->select('auditable_type')
            ->distinct()
            ->pluck('auditable_type');

        return $types
            ->mapWithKeys(fn (string $type): array => [$type => $this->modelLabel($type)])
            ->sort()
            ->all();
    }

    public function modelLabel(string $type): string
    {
        return match (class_basename($type)) {
            'Supplier' => 'Supplier',
            'SupplierBankAccount' => 'Rekening Supplier',
            'GovernancePolicy' => 'Governance Policy',
            'PurchaseRequest' => 'Purchase Request',
            'ApprovalRequest' => 'Approval Request',
            'ApprovalAction' => 'Approval Action',
            'PurchaseAllocation' => 'Purchase Allocation',
            'PurchaseOrder' => 'Purchase Order',
            'DeliverySchedule' => 'Delivery Schedule',
            'GoodsReceipt' => 'Goods Receipt',
            'FulfillmentDiscrepancy' => 'Fulfillment Discrepancy',
            'Invoice' => 'Invoice',
            'InvoiceAdjustment' => 'Invoice Adjustment',
            'Payment' => 'Payment',
            default => class_basename($type),
        };
    }

    public function objectLabel(AuditLog $log): string
    {
        $identifier = $log->auditable?->number
            ?? $log->auditable?->code
            ?? $log->auditable_id;

        return $this->modelLabel($log->auditable_type).' · '.$identifier;
    }

    /** @return Collection<int, string> */
    public function changedFields(AuditLog $log): Collection
    {
        return collect(array_keys($log->old_values ?? []))
            ->merge(array_keys($log->new_values ?? []))
            ->unique()
            ->sort()
            ->values();
    }

    public function changedFieldsLabel(AuditLog $log): string
    {
        $fields = $this->changedFields($log);

        return $fields->isEmpty() ? '-' : $fields->implode(', ');
    }

    public function changedFieldCount(AuditLog $log): int
    {
        return $this->changedFields($log)->count();
    }

    private function orModelIds(Builder $query, string $modelClass, Builder $ids): void
    {
        $morphClass = (new $modelClass)->getMorphClass();

        $query->orWhere(function (Builder $modelQuery) use ($morphClass, $ids): void {
            $modelQuery
                ->where('auditable_type', $morphClass)
                ->whereIn('auditable_id', $ids);
        });
    }
}
