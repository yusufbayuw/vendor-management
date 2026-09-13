<?php

namespace App\Filament\Exports;

use App\Models\AuditLog;
use App\Services\Analytics\AuditAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class AuditAnalyticsExporter extends Exporter
{
    protected static ?string $model = AuditLog::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('occurred_at')->label('Waktu'),
            ExportColumn::make('actor.name')->label('Actor'),
            ExportColumn::make('actor.email')->label('Email Actor'),
            ExportColumn::make('event')->label('Event'),
            ExportColumn::make('auditable_type')
                ->label('Tipe Objek')
                ->formatStateUsing(fn (string $state): string => self::metrics()->modelLabel($state)),
            ExportColumn::make('auditable_id')->label('Object ID'),
            ExportColumn::make('object_label')
                ->label('Objek')
                ->state(fn (AuditLog $record): string => self::metrics()->objectLabel($record)),
            ExportColumn::make('changed_fields')
                ->label('Field Berubah')
                ->state(fn (AuditLog $record): string => self::metrics()->changedFieldsLabel($record)),
            ExportColumn::make('changed_fields_count')
                ->label('Jumlah Field Berubah')
                ->state(fn (AuditLog $record): int => self::metrics()->changedFieldCount($record)),
            ExportColumn::make('ip_address')->label('IP Address'),
            ExportColumn::make('user_agent')->label('User Agent'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export audit analytics selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }

    private static function metrics(): AuditAnalyticsService
    {
        return app(AuditAnalyticsService::class);
    }
}
