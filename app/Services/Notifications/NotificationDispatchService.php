<?php

namespace App\Services\Notifications;

use App\Enums\SystemPermission;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Access\UserAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;

class NotificationDispatchService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function toKitchenPermission(
        SppgKitchen|int $kitchen,
        SystemPermission|string $permission,
        string $title,
        string $body,
        ?string $entityType = null,
        int|string|null $entityId = null,
        string $severity = 'info',
        ?string $url = null,
    ): int {
        $permissionName = $permission instanceof SystemPermission ? $permission->value : $permission;

        if (! $this->permissionExists($permissionName)) {
            return 0;
        }

        $kitchenModel = $kitchen instanceof SppgKitchen
            ? $kitchen
            : SppgKitchen::query()->findOrFail($kitchen);

        $recipients = User::permission($permissionName)
            ->get()
            ->filter(fn (User $user): bool => $this->access->canAccessKitchen($user, $kitchenModel));

        return $this->send($recipients, $title, $body, $entityType, $entityId, $severity, $url);
    }

    public function toPermission(
        SystemPermission|string $permission,
        string $title,
        string $body,
        ?string $entityType = null,
        int|string|null $entityId = null,
        string $severity = 'info',
        ?string $url = null,
    ): int {
        $permissionName = $permission instanceof SystemPermission ? $permission->value : $permission;

        if (! $this->permissionExists($permissionName)) {
            return 0;
        }

        return $this->send(
            User::permission($permissionName)->get(),
            $title,
            $body,
            $entityType,
            $entityId,
            $severity,
            $url,
        );
    }

    public function toSupplier(
        Supplier|int $supplier,
        string $title,
        string $body,
        ?string $entityType = null,
        int|string|null $entityId = null,
        string $severity = 'info',
        ?string $url = null,
    ): int {
        $supplierModel = $supplier instanceof Supplier
            ? $supplier
            : Supplier::query()->findOrFail($supplier);

        $recipients = $supplierModel->users()
            ->wherePivot('is_active', true)
            ->get();

        return $this->send($recipients, $title, $body, $entityType, $entityId, $severity, $url);
    }

    public function toUser(
        User $user,
        string $title,
        string $body,
        ?string $entityType = null,
        int|string|null $entityId = null,
        string $severity = 'info',
        ?string $url = null,
    ): void {
        Notification::sendNow($user, new SystemNotification(
            $title,
            $body,
            $entityType,
            $entityId,
            $severity,
            $url,
        ));
    }

    /** @param Collection<int, User> $recipients */
    private function send(
        Collection $recipients,
        string $title,
        string $body,
        ?string $entityType,
        int|string|null $entityId,
        string $severity,
        ?string $url,
    ): int {
        $recipients = $recipients->unique('id')->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        Notification::sendNow($recipients, new SystemNotification(
            $title,
            $body,
            $entityType,
            $entityId,
            $severity,
            $url,
        ));

        return $recipients->count();
    }

    private function permissionExists(string $permission): bool
    {
        return Permission::query()
            ->where('name', $permission)
            ->where('guard_name', 'web')
            ->exists();
    }
}
