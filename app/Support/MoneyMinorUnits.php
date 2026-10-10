<?php

namespace App\Support;

use DomainException;

final class MoneyMinorUnits
{
    public static function fromDecimal(string|int|float $amount): int
    {
        $value = (string) $amount;

        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $value, $matches)) {
            throw new DomainException('Nilai uang harus berupa angka dengan maksimal dua desimal.');
        }

        $major = (int) $matches[1];

        if ($major > intdiv(PHP_INT_MAX - 99, 100)) {
            throw new DomainException('Nilai uang melebihi batas yang dapat diproses.');
        }

        return $major * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
