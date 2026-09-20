<?php

namespace App\Listeners;

use App\Enums\GoodsReceiptStatus;
use App\Enums\SystemPermission;
use App\Events\GoodsReceiptStatusChanged;
use App\Models\GoodsReceipt;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyGoodsReceiptStatusChanged implements ShouldQueue
{
    public function handle(GoodsReceiptStatusChanged $event): void
    {
        if (GoodsReceiptStatus::tryFrom($event->status) !== GoodsReceiptStatus::PendingInspection) {
            return;
        }

        $receipt = GoodsReceipt::query()->with('kitchen')->find($event->goodsReceiptId);

        if ($receipt === null || $receipt->status !== GoodsReceiptStatus::PendingInspection) {
            return;
        }

        app(NotificationDispatchService::class)->toKitchenPermission(
            $receipt->kitchen,
            SystemPermission::GoodsReceiptInspect,
            'Penerimaan barang menunggu QC',
            "{$receipt->number} telah dicatat dan menunggu pemeriksaan kualitas.",
            GoodsReceipt::class,
            $receipt->getKey(),
            'warning',
            '/admin/goods-receipts',
        );
    }
}
