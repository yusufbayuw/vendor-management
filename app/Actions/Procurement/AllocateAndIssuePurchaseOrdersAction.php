<?php

namespace App\Actions\Procurement;

use App\Enums\OperationalProfile;
use App\Enums\SystemPermission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AllocateAndIssuePurchaseOrdersAction
{
    public function __construct(
        private readonly AllocatePurchaseRequestItemAction $allocateItem,
        private readonly CreateAndIssuePurchaseOrdersAction $createAndIssueOrders,
    ) {}

    /**
     * @param  array<int, array{
     *     purchase_request_item_id:int|string,
     *     supplier_id:int|string,
     *     quantity:float|int|string,
     *     unit_price:float|int|string,
     *     notes?:string|null
     * }>  $allocations
     * @return Collection<int, PurchaseOrder>
     */
    public function execute(PurchaseRequest $purchaseRequest, array $allocations, User $actor): Collection
    {
        $purchaseRequest->loadMissing('kitchen.organization');

        if ($purchaseRequest->kitchen?->organization?->operational_profile !== OperationalProfile::Lean) {
            throw new DomainException('Aksi satu langkah pembuatan pesanan hanya tersedia untuk profil Lean.');
        }

        foreach ([
            SystemPermission::PurchaseRequestAllocate,
            SystemPermission::PurchaseOrderCreate,
            SystemPermission::PurchaseOrderApprove,
            SystemPermission::PurchaseOrderIssue,
        ] as $permission) {
            if (! $actor->can($permission->value)) {
                throw new DomainException('User tidak memiliki izin lengkap untuk membuat pesanan Lean.');
            }
        }

        if ($allocations === []) {
            throw new DomainException('Minimal satu alokasi supplier harus diisi.');
        }

        return DB::transaction(function () use ($purchaseRequest, $allocations, $actor): Collection {
            foreach ($allocations as $row) {
                $item = PurchaseRequestItem::query()
                    ->where('purchase_request_id', $purchaseRequest->getKey())
                    ->findOrFail((int) ($row['purchase_request_item_id'] ?? 0));

                $supplier = Supplier::query()->findOrFail((int) ($row['supplier_id'] ?? 0));

                $this->allocateItem->execute(
                    $item,
                    $supplier,
                    (float) ($row['quantity'] ?? 0),
                    (float) ($row['unit_price'] ?? 0),
                    $actor,
                    filled($row['notes'] ?? null) ? (string) $row['notes'] : null,
                );
            }

            return $this->createAndIssueOrders->execute($purchaseRequest->refresh(), $actor);
        }, 3);
    }
}
