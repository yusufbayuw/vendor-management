<?php

namespace App\Services\Analytics;

use App\Enums\AccessScopeType;
use App\Enums\ApprovalActionType;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;

class GovernanceAnalyticsService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function query(User $user): Builder
    {
        $query = ApprovalRequest::query()
            ->with([
                'organization',
                'requester',
                'approvable',
                'actions.actor',
            ])
            ->withCount([
                'actions',
                'actions as approved_actions_count' => fn (Builder $query): Builder => $query
                    ->where('action', ApprovalActionType::Approved->value),
                'actions as rejected_actions_count' => fn (Builder $query): Builder => $query
                    ->where('action', ApprovalActionType::Rejected->value),
                'actions as self_approval_actions_count' => fn (Builder $query): Builder => $query
                    ->where('is_self_approval', true),
                'actions as override_actions_count' => fn (Builder $query): Builder => $query
                    ->whereNotNull('override_reason')
                    ->where('override_reason', '!=', ''),
            ]);

        if ($this->access->hasGlobalAccess($user)) {
            return $query;
        }

        $organizationIds = $this->access->scopeIds($user, AccessScopeType::Organization);
        $kitchenIds = $this->access->accessibleKitchenIds($user);

        return $query->where(function (Builder $scopeQuery) use ($organizationIds, $kitchenIds): void {
            if ($organizationIds->isNotEmpty()) {
                $scopeQuery->whereIn('organization_id', $organizationIds);
            } else {
                $scopeQuery->whereRaw('1 = 0');
            }

            if ($kitchenIds->isEmpty()) {
                return;
            }

            $scopeQuery->orWhere(function (Builder $kitchenScope) use ($kitchenIds): void {
                $kitchenScope
                    ->whereHasMorph(
                        'approvable',
                        [PurchaseRequest::class, PurchaseOrder::class, Invoice::class],
                        fn (Builder $approvableQuery): Builder => $approvableQuery->whereIn('sppg_kitchen_id', $kitchenIds),
                    )
                    ->orWhereHasMorph(
                        'approvable',
                        [Payment::class],
                        fn (Builder $paymentQuery): Builder => $paymentQuery->whereHas(
                            'invoice',
                            fn (Builder $invoiceQuery): Builder => $invoiceQuery->whereIn('sppg_kitchen_id', $kitchenIds),
                        ),
                    );
            });
        });
    }

    public function decisionHours(ApprovalRequest $request): ?float
    {
        if ($request->requested_at === null) {
            return null;
        }

        $end = $request->resolved_at;

        if ($end === null && $request->status === ApprovalStatus::Pending) {
            $end = now();
        }

        if ($end === null) {
            return null;
        }

        return round(($end->getTimestamp() - $request->requested_at->getTimestamp()) / 3600, 1);
    }

    public function approvalProgress(ApprovalRequest $request): string
    {
        $approved = (int) ($request->getAttribute('approved_actions_count') ?? $request->actions
            ->where('action', ApprovalActionType::Approved)
            ->count());

        return $approved.'/'.max(1, (int) $request->required_approvers);
    }

    public function hadSelfApproval(ApprovalRequest $request): bool
    {
        if ($request->getAttribute('self_approval_actions_count') !== null) {
            return (int) $request->getAttribute('self_approval_actions_count') > 0;
        }

        return $request->actions->contains('is_self_approval', true);
    }

    public function hadOverride(ApprovalRequest $request): bool
    {
        if ($request->getAttribute('override_actions_count') !== null) {
            return (int) $request->getAttribute('override_actions_count') > 0;
        }

        return $request->actions->contains(fn ($action): bool => filled($action->override_reason));
    }

    public function approvableLabel(ApprovalRequest $request): string
    {
        $type = class_basename($request->approvable_type);

        $label = match ($type) {
            'PurchaseRequest' => 'Purchase Request',
            'PurchaseOrder' => 'Purchase Order',
            'Invoice' => 'Invoice',
            'Payment' => 'Payment',
            'Supplier' => 'Supplier',
            'SupplierBankAccount' => 'Rekening Supplier',
            default => $type,
        };

        $identifier = $request->approvable?->number
            ?? $request->approvable?->code
            ?? $request->approvable_id;

        return $label.' · '.$identifier;
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
}
