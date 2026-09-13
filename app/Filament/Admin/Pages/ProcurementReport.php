<?php

namespace App\Filament\Admin\Pages;

use App\Enums\SystemPermission;
use App\Services\Reporting\ProcurementReportService;
use Filament\Pages\Page;
use UnitEnum;

class ProcurementReport extends Page
{
    protected static ?string $navigationLabel = 'Laporan Procurement';

    protected static ?string $title = 'Laporan Procurement';

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'reports/procurement';

    protected string $view = 'filament.admin.pages.procurement-report';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    /** @return array<string, int|float> */
    public function summary(): array
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return app(ProcurementReportService::class)->summary(
            $user,
            request()->query('from'),
            request()->query('to'),
        );
    }

    public function fromDate(): string
    {
        return (string) request()->query('from', today()->subDays(29)->toDateString());
    }

    public function toDate(): string
    {
        return (string) request()->query('to', today()->toDateString());
    }

    public function csvUrl(): string
    {
        return route('reports.procurement.csv', [
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
        ]);
    }
}
