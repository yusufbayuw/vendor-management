<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    private const HIDDEN_KEYS = ['password', 'remember_token', 'token', 'secret'];

    public function record(Model $model, string $event, array $oldValues = [], array $newValues = []): AuditLog
    {
        $request = app()->bound('request') ? request() : null;

        return AuditLog::query()->create([
            'actor_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => Arr::except($oldValues, self::HIDDEN_KEYS),
            'new_values' => Arr::except($newValues, self::HIDDEN_KEYS),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'occurred_at' => now(),
        ]);
    }
}
