<?php

namespace App\Jobs;

use App\Enums\SystemPermission;
use App\Models\LegacyImportBatch;
use App\Services\Access\UserAccessService;
use App\Services\Imports\LegacyImportService;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessLegacyImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $batchId) {}

    public function handle(LegacyImportService $imports, UserAccessService $access): void
    {
        $batch = LegacyImportBatch::query()->with('importer')->findOrFail($this->batchId);
        $actor = $batch->importer;

        if (! $actor?->is_active || ! $actor->can(SystemPermission::LegacyImportManage->value)
            || ! $access->canManageOrganization($actor, (int) $batch->organization_id)) {
            throw new DomainException('Pelaksana import tidak lagi memiliki permission atau scope organisasi yang sah.');
        }

        $imports->execute($batch, $actor);
    }

    public function failed(?Throwable $exception): void
    {
        $batch = LegacyImportBatch::query()->find($this->batchId);

        if ($batch !== null && in_array($batch->status, ['queued', 'pending', 'processing'], true)) {
            $batch->forceFill([
                'status' => 'failed',
                'summary' => [
                    'workflow_replayed' => false,
                    'dry_run' => (bool) $batch->dry_run,
                    'fatal_error' => $exception?->getMessage() ?? 'Worker gagal memproses job.',
                ],
                'imported_at' => now(),
            ])->save();
        }
    }
}
