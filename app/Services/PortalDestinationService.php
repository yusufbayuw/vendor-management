<?php

namespace App\Services;

use App\Enums\SystemRole;
use App\Models\User;

class PortalDestinationService
{
    public function pathFor(User $user): ?string
    {
        if (! $user->is_active) {
            return null;
        }

        if ($user->hasRole(SystemRole::SuperAdmin->value) || $user->hasAnyRole($this->internalRoles())) {
            return '/admin';
        }

        if ($user->hasAnyRole([
            SystemRole::SupplierAdmin->value,
            SystemRole::SupplierOperator->value,
        ])) {
            return '/supplier';
        }

        return null;
    }

    public function intendedUrlIsAllowed(User $user, ?string $url): bool
    {
        if (blank($url)) {
            return false;
        }

        $path = parse_url((string) $url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return false;
        }

        return match ($this->pathFor($user)) {
            '/admin' => str_starts_with($path, '/admin')
                || str_starts_with($path, '/reports/')
                || str_starts_with($path, '/documents/')
                || str_starts_with($path, '/files/')
                || str_starts_with($path, '/push/'),
            '/supplier' => str_starts_with($path, '/supplier')
                || str_starts_with($path, '/documents/')
                || str_starts_with($path, '/files/')
                || str_starts_with($path, '/push/'),
            default => false,
        };
    }

    /** @return array<int, string> */
    private function internalRoles(): array
    {
        return [
            SystemRole::PanelUser->value,
            SystemRole::CentralManager->value,
            SystemRole::MasterDataSteward->value,
            SystemRole::SppgManager->value,
            SystemRole::Requester->value,
            SystemRole::Procurement->value,
            SystemRole::ProcurementManager->value,
            SystemRole::Receiver->value,
            SystemRole::QualityControl->value,
            SystemRole::Finance->value,
            SystemRole::FinanceManager->value,
            SystemRole::Auditor->value,
        ];
    }
}
