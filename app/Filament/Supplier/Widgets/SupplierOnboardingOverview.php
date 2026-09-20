<?php

namespace App\Filament\Supplier\Widgets;

use App\Enums\SupplierStatus;
use App\Filament\Supplier\Resources\BankAccounts\SupplierBankAccountResource;
use App\Filament\Supplier\Resources\Documents\SupplierDocumentResource;
use App\Filament\Supplier\Resources\Products\SupplierProductResource;
use App\Filament\Supplier\Resources\Profiles\SupplierProfileResource;
use App\Models\Supplier;
use App\Services\Supplier\SupplierOnboardingService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierOnboardingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $supplier = static::currentSupplier();

        if ($supplier === null) {
            return false;
        }

        if (! in_array($supplier->status, [
            SupplierStatus::Draft,
            SupplierStatus::Submitted,
            SupplierStatus::UnderReview,
            SupplierStatus::RevisionRequired,
            SupplierStatus::Approved,
            SupplierStatus::Rejected,
        ], true)) {
            return false;
        }

        return ! app(SupplierOnboardingService::class)->summary($supplier)['complete'];
    }

    protected function getStats(): array
    {
        $supplier = static::currentSupplier();

        if ($supplier === null) {
            return [];
        }

        $summary = app(SupplierOnboardingService::class)->summary($supplier);
        $sections = $summary['sections'];
        $missingPreview = collect($summary['missing'])->take(3)->implode(', ');
        $remaining = count($summary['missing']);

        return [
            Stat::make('Kelengkapan Data', $summary['percentage'].'%')
                ->description($remaining > 0
                    ? $remaining.' item belum lengkap'.($missingPreview !== '' ? ': '.$missingPreview : '')
                    : 'Seluruh data onboarding sudah lengkap.')
                ->icon('heroicon-o-chart-bar')
                ->color($summary['percentage'] >= 80 ? 'warning' : 'danger'),

            Stat::make('Profil Supplier', $sections['profile'] ? 'Lengkap' : 'Perlu dilengkapi')
                ->description('Identitas, alamat, wilayah, dan legal identifier')
                ->icon($sections['profile'] ? 'heroicon-o-check-circle' : 'heroicon-o-pencil-square')
                ->color($sections['profile'] ? 'success' : 'warning')
                ->url(SupplierProfileResource::getUrl('index')),

            Stat::make('Dokumen Legal', $sections['documents'] ? 'Tersedia' : 'Belum tersedia')
                ->description('Upload dokumen pendukung legalitas supplier')
                ->icon($sections['documents'] ? 'heroicon-o-check-circle' : 'heroicon-o-document-plus')
                ->color($sections['documents'] ? 'success' : 'warning')
                ->url(SupplierDocumentResource::getUrl('index')),

            Stat::make('Rekening Bank', $sections['bank'] ? 'Lengkap' : 'Belum lengkap')
                ->description('Rekening yang digunakan dalam proses pembayaran')
                ->icon($sections['bank'] ? 'heroicon-o-check-circle' : 'heroicon-o-banknotes')
                ->color($sections['bank'] ? 'success' : 'warning')
                ->url(SupplierBankAccountResource::getUrl('index')),

            Stat::make('Komoditas / Produk', $sections['products'] ? 'Sudah dipilih' : 'Belum dipilih')
                ->description('Master produk yang dapat dipasok oleh supplier')
                ->icon($sections['products'] ? 'heroicon-o-check-circle' : 'heroicon-o-archive-box-arrow-down')
                ->color($sections['products'] ? 'success' : 'warning')
                ->url(SupplierProductResource::getUrl('index')),
        ];
    }

    private static function currentSupplier(): ?Supplier
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return $user->suppliers()
            ->wherePivot('is_active', true)
            ->orderByPivot('is_owner', 'desc')
            ->orderBy('suppliers.id')
            ->first();
    }
}
