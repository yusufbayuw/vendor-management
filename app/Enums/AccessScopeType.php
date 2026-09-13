<?php

namespace App\Enums;

enum AccessScopeType: string
{
    case Global = 'global';
    case Organization = 'organization';
    case SppgKitchen = 'sppg_kitchen';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Global',
            self::Organization => 'Organisasi',
            self::SppgKitchen => 'Dapur SPPG',
            self::Supplier => 'Supplier',
        };
    }
}
