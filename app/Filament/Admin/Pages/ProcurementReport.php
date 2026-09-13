<?php

namespace App\Filament\Admin\Pages;

use App\Enums\SystemPermission;
use App\Filament\Admin\Widgets\ProcurementReportOverview;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class ProcurementReport extends Page
{
    protected static ?string $navigationLabel = 'Laporan Procurement';

    protected static ?string $title = 'Laporan Procurement';

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'reports/procurement';

    protected string $view = 'filament.admin.pages.procurement-report';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'from' => (string) request()->query('from', today()->subDays(29)->toDateString()),
            'to' => (string) request()->query('to', today()->toDateString()),
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Laporan mengikuti scope akses user. User SPPG hanya melihat transaksi dapur yang ditugaskan, sedangkan user global melihat seluruh SPPG.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Filter Periode')
                        ->description('Pilih rentang tanggal untuk memperbarui ringkasan dan file CSV.')
                        ->schema([
                            DatePicker::make('from')
                                ->label('Dari tanggal')
                                ->required(),
                            DatePicker::make('to')
                                ->label('Sampai tanggal')
                                ->required(),
                        ])
                        ->columns(2),
                ])
                    ->livewireSubmitHandler('applyFilters')
                    ->footer([
                        Actions::make([
                            Action::make('applyFilters')
                                ->label('Terapkan Filter')
                                ->icon('heroicon-o-funnel')
                                ->submit('applyFilters'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function applyFilters(): void
    {
        $data = $this->form->getState();

        $this->redirect(static::getUrl([
            'from' => $data['from'],
            'to' => $data['to'],
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => $this->csvUrl()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProcurementReportOverview::make([
                'fromDate' => $this->fromDate(),
                'toDate' => $this->toDate(),
            ]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function fromDate(): string
    {
        return (string) ($this->data['from'] ?? request()->query('from', today()->subDays(29)->toDateString()));
    }

    public function toDate(): string
    {
        return (string) ($this->data['to'] ?? request()->query('to', today()->toDateString()));
    }

    public function csvUrl(): string
    {
        return route('reports.procurement.csv', [
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
        ]);
    }
}
