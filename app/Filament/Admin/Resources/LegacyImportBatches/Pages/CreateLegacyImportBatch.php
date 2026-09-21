<?php

namespace App\Filament\Admin\Resources\LegacyImportBatches\Pages;

use App\Filament\Admin\Resources\LegacyImportBatches\LegacyImportBatchResource;
use App\Models\LegacyImportBatch;
use App\Services\Imports\LegacyImportService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Throwable;

class CreateLegacyImportBatch extends CreateRecord
{
    protected static string $resource = LegacyImportBatchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['original_filename'] = basename((string) $data['file_path']);
        $data['status'] = 'pending';
        $data['imported_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var LegacyImportBatch $batch */
        $batch = $this->record;

        try {
            app(LegacyImportService::class)->execute($batch, auth()->user());

            $batch->refresh();

            Notification::make()
                ->title($batch->failed_rows > 0 ? 'Import selesai dengan catatan' : 'Import selesai')
                ->body("Berhasil {$batch->imported_rows} dari {$batch->total_rows} baris; gagal {$batch->failed_rows}.")
                ->color($batch->failed_rows > 0 ? 'warning' : 'success')
                ->send();
        } catch (DomainException $exception) {
            Notification::make()->danger()->title('Import gagal')->body($exception->getMessage())->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Import gagal')->body('Terjadi kesalahan saat memproses file. Detail tersimpan pada batch import.')->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
