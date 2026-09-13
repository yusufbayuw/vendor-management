<?php

namespace App\Enums;

enum OperationalProfile: string
{
    case Lean = 'lean';
    case Standard = 'standard';
    case Strict = 'strict';

    public function label(): string
    {
        return match ($this) {
            self::Lean => 'Lean - SDM Minimal',
            self::Standard => 'Standard',
            self::Strict => 'Strict - Segregation of Duties',
        };
    }
}
