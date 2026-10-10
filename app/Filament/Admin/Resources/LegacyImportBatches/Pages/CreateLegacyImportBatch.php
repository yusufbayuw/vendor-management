<?php

namespace App\Filament\Admin\Resources\LegacyImportBatches\Pages;

use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\LegacyImportBatches\LegacyImportBatchResource;
use App\Jobs\ProcessLegacyImportBatch;
use App\Models\LegacyImportBatch;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLegacyImportBatch extends CreateRecord
{
    protected static string $resource = LegacyImportBatchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['original_filename'] = basename((string) $data['file_path']);
        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active
            || ! $user->can(SystemPermission::LegacyImportManage->value)
            || ! app(UserAccessService::class)->canManageOrganization($user, (int) $data['organization_id'])) {
            abort(403);
        }

        $data['status'] = 'queued';
        $data['imported_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var LegacyImportBatch $batch */
        $batch = $this->record;

        ProcessLegacyImportBatch::dispatch($batch->getKey())->afterCommit();

        Notification::make()
            ->success()
            ->title($batch->dry_run ? 'Simulasi import dijadwalkan' : 'Import dijadwalkan')
            ->body('Batch #'.$batch->getKey().' masuk antrean. Hasil akan tampil pada daftar setelah diproses worker.')
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
