<?php

namespace App\Listeners;

use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemPermission;
use App\Events\PurchaseRequestStatusChanged;
use App\Models\PurchaseRequest;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPurchaseRequestStatusChanged implements ShouldQueue
{
    public function handle(PurchaseRequestStatusChanged $event): void
    {
        $status = PurchaseRequestStatus::tryFrom($event->status);
        $request = PurchaseRequest::query()->with('kitchen')->find($event->purchaseRequestId);

        if ($status === null || $request === null || $request->status !== $status) {
            return;
        }

        $notifications = app(NotificationDispatchService::class);

        if ($status === PurchaseRequestStatus::Submitted) {
            $notifications->toKitchenPermission(
                $request->kitchen,
                SystemPermission::PurchaseRequestApprove,
                'Purchase Request menunggu approval',
                "{$request->number} telah diajukan dan menunggu persetujuan.",
                PurchaseRequest::class,
                $request->getKey(),
                'warning',
                '/admin/purchase-requests',
            );
        }

        if ($status === PurchaseRequestStatus::Approved) {
            $notifications->toKitchenPermission(
                $request->kitchen,
                SystemPermission::PurchaseRequestAllocate,
                'Purchase Request disetujui',
                "{$request->number} siap dialokasikan ke supplier.",
                PurchaseRequest::class,
                $request->getKey(),
                'success',
                '/admin/purchase-requests',
            );
        }
    }
}
