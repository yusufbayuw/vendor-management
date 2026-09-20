<?php

namespace App\Filament\Admin\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MasterDataDuplicateGuard
{
    /** @param class-string<Model> $modelClass */
    public static function hint(string $modelClass, ?string $value, ?int $ignoreId = null): ?string
    {
        if (blank($value)) {
            return null;
        }

        $needle = static::normalize((string) $value);

        if ($needle === '') {
            return null;
        }

        $threshold = max(2, (int) ceil(strlen($needle) * 0.25));

        $matches = $modelClass::query()
            ->select(['id', 'name'])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->limit(300)
            ->get()
            ->map(function (Model $record) use ($needle, $threshold): ?array {
                $candidate = static::normalize((string) $record->getAttribute('name'));

                if ($candidate === '') {
                    return null;
                }

                if ($candidate === $needle) {
                    return ['score' => 0, 'name' => (string) $record->getAttribute('name')];
                }

                if (str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
                    return ['score' => 1, 'name' => (string) $record->getAttribute('name')];
                }

                $distance = levenshtein($needle, $candidate);

                if ($distance <= $threshold) {
                    return ['score' => 2 + $distance, 'name' => (string) $record->getAttribute('name')];
                }

                return null;
            })
            ->filter()
            ->sortBy('score')
            ->take(3)
            ->pluck('name')
            ->values();

        if ($matches->isEmpty()) {
            return null;
        }

        return 'Kemungkinan sudah ada: '.$matches->implode(', ').'. Periksa sebelum membuat data baru.';
    }

    /** @param class-string<Model> $modelClass */
    public static function assertNoExactName(string $modelClass, string $value, ?int $ignoreId = null): void
    {
        $needle = static::normalize($value);

        $match = $modelClass::query()
            ->select(['id', 'name'])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get()
            ->first(static fn (Model $record): bool => static::normalize((string) $record->getAttribute('name')) === $needle);

        if ($match !== null) {
            throw ValidationException::withMessages([
                'name' => 'Data dengan nama serupa sudah ada: '.$match->getAttribute('name').'.',
            ]);
        }
    }

    private static function normalize(string $value): string
    {
        $ascii = Str::ascii(Str::lower(trim($value)));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }
}
