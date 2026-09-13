<?php

namespace App\Observers;

use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    public function __construct(private readonly AuditService $audit) {}

    public function created(Model $model): void
    {
        $this->audit->record($model, 'created', [], $this->withoutNoise($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->withoutNoise($model->getChanges());
        if ($changes === []) {
            return;
        }

        $old = [];
        foreach (array_keys($changes) as $key) {
            $old[$key] = $model->getRawOriginal($key);
        }

        $this->audit->record($model, 'updated', $old, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->audit->record($model, 'deleted', $this->withoutNoise($model->getAttributes()), []);
    }

    private function withoutNoise(array $values): array
    {
        unset($values['created_at'], $values['updated_at']);
        return $values;
    }
}
