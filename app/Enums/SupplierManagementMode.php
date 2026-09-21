<?php

namespace App\Enums;

enum SupplierManagementMode: string
{
    case SelfService = 'self_service';
    case AdminManaged = 'admin_managed';

    public function label(): string
    {
        return match ($this) {
            self::SelfService => 'Portal Supplier',
            self::AdminManaged => 'Dikelola Admin',
        };
    }
}
