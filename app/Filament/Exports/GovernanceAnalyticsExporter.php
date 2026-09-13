<?php

namespace App\Filament\Exports;

use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Models\ApprovalRequest;
use App\Services\Analytics\GovernanceAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class GovernanceAnalyticsExporter extends Exporter
{
    protected static ?string $model = ApprovalRequest::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('requested_at')->label('Diminta Pada'),
            ExportColumn::make('resolved_at')->label('Selesai Pada'),
            ExportColumn::make('organization.name')->label('Organisasi'),
            ExportColumn::make('process')
                ->label('Proses')
                ->formatStateUsing(fn ($state): string => self::processLabel($state)),
            ExportColumn::make('approvable_label')
                ->label('Objek Approval')
                ->state(fn (ApprovalRequest $record): string => self::metrics()->approvableLabel($record)),
            ExportColumn::make('requester.name')->label('Requester'),
            ExportColumn::make('amount')->label('Nilai'),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
            ExportColumn::make('required_approvers')->label('Required Approvers'),
            ExportColumn::make('approval_progress')
                ->label('Progress Approval')
                ->state(fn (ApprovalRequest $record): string => self::metrics()->approvalProgress($record)),
            ExportColumn::make('self_approval_allowed')
                ->label('Self Approval Diizinkan')
                ->formatStateUsing(fn ($state): string => $state ? 'Ya' : 'Tidak'),
            ExportColumn::make('had_self_approval')
                ->label('Terjadi Self Approval')
                ->state(fn (ApprovalRequest $record): string => self::metrics()->hadSelfApproval($record) ? 'Ya' : 'Tidak'),
            ExportColumn::make('requires_override_reason')
                ->label('Override Reason Wajib')
                ->formatStateUsing(fn ($state): string => $state ? 'Ya' : 'Tidak'),
            ExportColumn::make('had_override')
                ->label('Terjadi Override')
                ->state(fn (ApprovalRequest $record): string => self::metrics()->hadOverride($record) ? 'Ya' : 'Tidak'),
            ExportColumn::make('decision_hours')
                ->label('Decision Time (jam)')
                ->state(fn (ApprovalRequest $record): ?float => self::metrics()->decisionHours($record)),
            ExportColumn::make('actions_count')->label('Jumlah Action'),
            ExportColumn::make('approved_actions_count')->label('Approved Actions'),
            ExportColumn::make('rejected_actions_count')->label('Rejected Actions'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export governance analytics selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }

    private static function metrics(): GovernanceAnalyticsService
    {
        return app(GovernanceAnalyticsService::class);
    }

    private static function processLabel($state): string
    {
        $process = $state instanceof GovernanceProcess ? $state : GovernanceProcess::tryFrom((string) $state);

        return $process?->label() ?? (string) $state;
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof ApprovalStatus ? $state : ApprovalStatus::tryFrom((string) $state);

        return match ($status) {
            ApprovalStatus::Pending => 'Pending',
            ApprovalStatus::Approved => 'Approved',
            ApprovalStatus::Rejected => 'Rejected',
            ApprovalStatus::Cancelled => 'Cancelled',
            default => (string) $state,
        };
    }
}
