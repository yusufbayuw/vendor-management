<?php

namespace App\Actions\Procurement;

use App\Enums\OperationalProfile;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CreateAndIssuePurchaseOrdersAction
{
    public function __construct(
        private readonly GeneratePurchaseOrdersAction $generatePurchaseOrders,
        private readonly SubmitPurchaseOrderForApprovalAction $submitForApproval,
        private readonly IssuePurchaseOrderAction $issuePurchaseOrder,
    ) {}

    /** @return Collection<int, PurchaseOrder> */
    public function execute(PurchaseRequest $purchaseRequest, User $actor): Collection
    {
        $purchaseRequest->loadMissing('kitchen.organization');

        if ($purchaseRequest->kitchen?->organization?->operational_profile !== OperationalProfile::Lean) {
            throw new DomainException('Aksi satu langkah PO hanya tersedia untuk profil Lean.');
        }

        foreach ([
            SystemPermission::PurchaseOrderCreate,
            SystemPermission::PurchaseOrderApprove,
            SystemPermission::PurchaseOrderIssue,
        ] as $permission) {
            if (! $actor->can($permission->value)) {
                throw new DomainException('User tidak memiliki izin lengkap untuk membuat dan menerbitkan PO Lean.');
            }
        }

        return DB::transaction(function () use ($purchaseRequest, $actor): Collection {
            $orders = $this->generatePurchaseOrders->execute($purchaseRequest, $actor);

            foreach ($orders as $order) {
                if ($order->status === PurchaseOrderStatus::Draft) {
                    $order = $this->submitForApproval->execute($order, $actor);
                }

                if ($order->status === PurchaseOrderStatus::Approved) {
                    $this->issuePurchaseOrder->execute($order);
                }
            }

            return $purchaseRequest->purchaseOrders()->with('items')->get();
        }, 3);
    }
}
