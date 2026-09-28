<?php

namespace App\Actions\Fulfillment;

use App\Actions\Procurement\AcknowledgePurchaseOrderAction;
use App\Enums\OperationalProfile;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Models\DeliverySchedule;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateAndConfirmDeliveryScheduleAction
{
    public function __construct(
        private readonly CreateDeliveryScheduleAction $createDeliverySchedule,
        private readonly ConfirmDeliveryScheduleAction $confirmDeliverySchedule,
        private readonly AcknowledgePurchaseOrderAction $acknowledgePurchaseOrder,
    ) {}

    /** @param array<int, float|int|string> $items */
    public function execute(
        PurchaseOrder $purchaseOrder,
        array $items,
        Carbon|string $plannedDeliveryAt,
        User $actor,
        ?string $supplierNotes = null,
    ): DeliverySchedule {
        $purchaseOrder->loadMissing('kitchen.organization');

        if ($purchaseOrder->kitchen?->organization?->operational_profile !== OperationalProfile::Lean) {
            throw new DomainException('Konfirmasi jadwal otomatis hanya tersedia untuk profil Lean.');
        }

        if (! $actor->can(SystemPermission::DeliveryManage->value)) {
            throw new DomainException('User tidak memiliki izin untuk mengelola pengiriman.');
        }

        return DB::transaction(function () use ($purchaseOrder, $items, $plannedDeliveryAt, $actor, $supplierNotes): DeliverySchedule {
            if ($purchaseOrder->status === PurchaseOrderStatus::Issued) {
                $purchaseOrder = $this->acknowledgePurchaseOrder->execute(
                    $purchaseOrder,
                    $actor,
                    'PO diterima melalui pembuatan jadwal pengiriman.',
                );
            }

            $schedule = $this->createDeliverySchedule->execute(
                $purchaseOrder,
                $items,
                $plannedDeliveryAt,
                $actor,
                $supplierNotes,
            );

            return $this->confirmDeliverySchedule->execute($schedule, $actor);
        }, 3);
    }
}
