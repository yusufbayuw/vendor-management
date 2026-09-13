<?php

namespace App\Actions\Fulfillment;

use App\Enums\DeliveryScheduleStatus;
use App\Models\DeliverySchedule;
use DomainException;

class MarkDeliveryInTransitAction
{
    /** @param array{driver_name?: string|null, driver_phone?: string|null, vehicle_number?: string|null, estimated_arrival_at?: mixed, delivery_note_number?: string|null, delivery_note_file?: string|null} $data */
    public function execute(DeliverySchedule $schedule, array $data = []): DeliverySchedule
    {
        if ($schedule->status !== DeliveryScheduleStatus::Confirmed) {
            throw new DomainException('Pengiriman hanya dapat diberangkatkan setelah jadwal dikonfirmasi.');
        }

        $schedule->forceFill([
            'status' => DeliveryScheduleStatus::InTransit,
            'driver_name' => $data['driver_name'] ?? $schedule->driver_name,
            'driver_phone' => $data['driver_phone'] ?? $schedule->driver_phone,
            'vehicle_number' => $data['vehicle_number'] ?? $schedule->vehicle_number,
            'estimated_arrival_at' => $data['estimated_arrival_at'] ?? $schedule->estimated_arrival_at,
            'delivery_note_number' => $data['delivery_note_number'] ?? $schedule->delivery_note_number,
            'delivery_note_file' => $data['delivery_note_file'] ?? $schedule->delivery_note_file,
            'departed_at' => now(),
        ])->save();

        return $schedule->refresh();
    }
}
