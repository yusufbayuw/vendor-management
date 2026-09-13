<?php

namespace App\Actions\Fulfillment;

use App\Enums\DeliveryScheduleStatus;
use App\Models\DeliverySchedule;
use App\Models\User;
use DomainException;

class ConfirmDeliveryScheduleAction
{
    public function execute(DeliverySchedule $schedule, User $actor): DeliverySchedule
    {
        if (! in_array($schedule->status, [DeliveryScheduleStatus::Draft, DeliveryScheduleStatus::Planned], true)) {
            throw new DomainException('Jadwal ini tidak dapat dikonfirmasi pada status saat ini.');
        }

        $schedule->forceFill([
            'status' => DeliveryScheduleStatus::Confirmed,
            'confirmed_by' => $actor->getKey(),
            'confirmed_at' => now(),
        ])->save();

        return $schedule->refresh();
    }
}
