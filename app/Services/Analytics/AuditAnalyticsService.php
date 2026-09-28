<?php

namespace App\Services\Analytics;

use App\Enums\AccessScopeType;
use App\Enums\BusinessFlowStage;
use App\Enums\GovernanceProcess;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleItem;
use App\Models\FulfillmentDiscrepancy;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\GovernancePolicy;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Payment;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Workflow\ProcureToPayLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AuditAnalyticsService
{
    public function __construct(
        private readonly UserAccessService $access,
        private readonly GovernanceAnalyticsService $governanceAnalytics,
        private readonly ProcureToPayLifecycleService $lifecycle,
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

    public function businessStage(AuditLog $log): ?BusinessFlowStage
    {
        $context = $this->lifecycle->contextForEntity(
            $log->auditable_type,
            $log->auditable_id,
        );

        return filled($context['business_stage'])
            ? BusinessFlowStage::tryFrom((string) $context['business_stage'])
            : $this->lifecycle->stageForType($log->auditable_type);
    }

    public function businessStageLabel(AuditLog $log): string
    {
        return $this->businessStage($log)?->navigationLabel() ?? 'Pendukung';
    }

    public function transactionReference(AuditLog $log): string
    {
        $context = $this->lifecycle->contextForEntity(
            $log->auditable_type,
            $log->auditable_id,
        );

        return $context['transaction_po_number']
            ?? $context['purchase_request_number']
            ?? '-';
    }

    /** @return array<string, string> */
    public function businessStageOptions(): array
    {
        return collect(BusinessFlowStage::cases())
            ->mapWithKeys(fn (BusinessFlowStage $stage): array => [
                $stage->value => $stage->navigationLabel(),
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function morphTypesForStage(BusinessFlowStage $stage): array
    {
        $classes = match ($stage) {
            BusinessFlowStage::PurchaseRequest => [
                PurchaseRequest::class,
                PurchaseRequestItem::class,
                PurchaseAllocation::class,
            ],
            BusinessFlowStage::PurchaseOrder => [
                PurchaseOrder::class,
                PurchaseOrderItem::class,
            ],
            BusinessFlowStage::Delivery => [
                DeliverySchedule::class,
                DeliveryScheduleItem::class,
            ],
            BusinessFlowStage::Receiving => [
                GoodsReceipt::class,
                GoodsReceiptItem::class,
                FulfillmentDiscrepancy::class,
            ],
            BusinessFlowStage::Invoice => [
                Invoice::class,
                InvoiceAdjustment::class,
            ],
            BusinessFlowStage::Payment => [
                Payment::class,
            ],
        };

        return array_map(
            static fn (string $class): string => (new $class)->getMorphClass(),
            $classes,
        );
    }

    public function applyBusinessStageFilter(Builder $query, BusinessFlowStage $stage): Builder
    {
        $processes = match ($stage) {
            BusinessFlowStage::PurchaseRequest => [GovernanceProcess::PurchaseRequestApproval->value],
            BusinessFlowStage::PurchaseOrder => [GovernanceProcess::PurchaseOrderApproval->value],
            BusinessFlowStage::Delivery => [],
            BusinessFlowStage::Receiving => [GovernanceProcess::PurchaseOrderExceptionClosing->value],
            BusinessFlowStage::Invoice => [GovernanceProcess::InvoiceApproval->value],
            BusinessFlowStage::Payment => [GovernanceProcess::PaymentVerification->value],
        };

        $approvalRequestMorph = (new ApprovalRequest)->getMorphClass();
        $approvalActionMorph = (new ApprovalAction)->getMorphClass();

        return $query->where(function (Builder $stageQuery) use (
            $stage,
            $processes,
            $approvalRequestMorph,
            $approvalActionMorph,
        ): void {
            $stageQuery->whereIn('auditable_type', $this->morphTypesForStage($stage));

            if ($processes === []) {
                return;
            }

            $stageQuery
                ->orWhere(function (Builder $approvalQuery) use ($approvalRequestMorph, $processes): void {
                    $approvalQuery
                        ->where('auditable_type', $approvalRequestMorph)
                        ->whereIn(
                            'auditable_id',
                            ApprovalRequest::query()->whereIn('process', $processes)->select('id'),
                        );
                })
                ->orWhere(function (Builder $actionQuery) use ($approvalActionMorph, $processes): void {
                    $actionQuery
                        ->where('auditable_type', $approvalActionMorph)
                        ->whereIn(
                            'auditable_id',
                            ApprovalAction::query()
                                ->whereHas('approvalRequest', fn (Builder $query): Builder => $query->whereIn('process', $processes))
                                ->select('id'),
                        );
                });
        });
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
