<?php

namespace App\Support;

use Filament\Support\RawJs;

final class UiNumber
{
    public static function maskFor(string $fieldName): RawJs
    {
        $precision = self::precisionFor($fieldName);

        return RawJs::make("\$money(\$input, ',', '.', {$precision})");
    }

    public static function formatForInput(mixed $state, string $fieldName): mixed
    {
        if ($state === null || $state === '') {
            return $state;
        }

        $numeric = self::toFloat($state, $fieldName);

        if ($numeric === null) {
            return $state;
        }

        $precision = self::precisionFor($fieldName);
        $formatted = number_format($numeric, $precision, ',', '.');

        if ($precision > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted;
    }

    public static function normalizeForStorage(mixed $state, string $fieldName): mixed
    {
        if ($state === null || $state === '') {
            return $state;
        }

        if (is_int($state) || is_float($state)) {
            return $state;
        }

        $value = self::clean((string) $state);

        if ($value === '') {
            return $value;
        }

        if (str_contains($value, ',')) {
            return str_replace(',', '.', str_replace('.', '', $value));
        }

        if (str_contains($value, '.')) {
            // UI menggunakan titik sebagai pemisah ribuan. Nilai raw dari backend
            // sudah diformat menjadi bentuk UI sebelum pengguna mengedit field.
            return str_replace('.', '', $value);
        }

        return $value;
    }

    public static function precisionFor(string $fieldName): int
    {
        $name = strtolower($fieldName);

        if (str_contains($name, 'qty') || str_contains($name, 'quantity')) {
            return 4;
        }

        if (str_contains($name, 'temperature')) {
            return 2;
        }

        if (
            str_contains($name, 'amount')
            || str_contains($name, 'price')
            || str_contains($name, 'rate')
            || str_contains($name, 'percent')
            || str_contains($name, 'threshold')
        ) {
            return 2;
        }

        return 4;
    }

    private static function toFloat(mixed $state, string $fieldName): ?float
    {
        if (is_int($state) || is_float($state)) {
            return (float) $state;
        }

        $value = self::clean((string) $state);

        if ($value === '') {
            return null;
        }

        // Nilai dari database memakai titik sebagai decimal separator. Nilai dari
        // UI memakai titik untuk ribuan dan koma untuk pecahan.
        if (is_numeric($value) && ! str_contains($value, ',')) {
            return (float) $value;
        }

        $normalized = self::normalizeForStorage($value, $fieldName);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function clean(string $value): string
    {
        return trim(str_replace(["\u{00A0}", ' '], '', $value));
    }
}
