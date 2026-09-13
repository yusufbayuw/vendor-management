<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditService
{
    private const HIDDEN_KEYS = [
        'password',
        'password_confirmation',
        'remember_token',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'api_key',
        'otp',
    ];

    private const MASKED_KEYS = [
        'account_number',
        'destination_account_number',
        'bank_account_number',
        'npwp',
    ];

    public function record(Model $model, string $event, array $oldValues = [], array $newValues = []): AuditLog
    {
        $request = app()->bound('request') ? request() : null;

        return AuditLog::query()->create([
            'actor_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'occurred_at' => now(),
        ]);
    }

    private function sanitize(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $normalizedKey = Str::lower((string) $key);

            if ($this->shouldHide($normalizedKey)) {
                continue;
            }

            if ($this->shouldMask($normalizedKey)) {
                $sanitized[$key] = $this->mask($value);

                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->sanitize($value)
                : $value;
        }

        return $sanitized;
    }

    private function shouldHide(string $key): bool
    {
        return in_array($key, self::HIDDEN_KEYS, true)
            || str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_ends_with($key, '_token')
            || str_ends_with($key, '_otp');
    }

    private function shouldMask(string $key): bool
    {
        return in_array($key, self::MASKED_KEYS, true)
            || str_contains($key, 'account_number')
            || str_contains($key, 'nomor_rekening');
    }

    private function mask(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->mask($item), $value);
        }

        if (! is_scalar($value)) {
            return '[MASKED]';
        }

        $string = (string) $value;
        $length = mb_strlen($string);

        if ($length <= 4) {
            return str_repeat('*', max(4, $length));
        }

        return str_repeat('*', $length - 4).mb_substr($string, -4);
    }
}
