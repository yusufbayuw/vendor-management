<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\DevicePushNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DevicePushSender
{
    /** @param Collection<int, User> $recipients */
    public function send(Collection $recipients, DevicePushNotification $notification): void
    {
        if (! $this->configured()) {
            return;
        }

        $recipientIds = $recipients
            ->filter(fn (User $user): bool => $user->exists && $user->is_active)
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

        if ($recipientIds === []) {
            return;
        }

        $deliver = function () use ($recipientIds, $notification): void {
            User::query()
                ->whereKey($recipientIds)
                ->where('is_active', true)
                ->whereHas('pushSubscriptions')
                ->get()
                ->each(function (User $recipient) use ($notification): void {
                    try {
                        $recipient->notifyNow(clone $notification);
                    } catch (Throwable $exception) {
                        Log::warning('Web Push delivery failed.', [
                            'user_id' => $recipient->getKey(),
                            'notification_type' => $notification->type,
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                });
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($deliver);

            return;
        }

        $deliver();
    }

    public function configured(): bool
    {
        return filled(config('webpush.vapid.subject'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }
}
