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
            Stat::make('Status Pendaftaran', $supplier->status->label())
                ->description($this->statusDescription($supplier->status))
                ->icon('heroicon-o-identification')
                ->color($this->statusColor($supplier->status)),

            Stat::make('Progress Onboarding', $summary['percentage'].'%')
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

            Stat::make('Katalog Produk', $sections['products'] ? 'Sudah dipilih' : 'Belum dipilih')
                ->description('Komoditas / produk master yang dapat dipasok supplier')
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

    private function statusDescription(SupplierStatus $status): string
    {
        return match ($status) {
            SupplierStatus::Draft => 'Lengkapi data yang masih kurang lalu ajukan verifikasi.',
            SupplierStatus::Submitted => 'Pengajuan sudah dikirim dan menunggu pemeriksaan.',
            SupplierStatus::UnderReview => 'Tim sedang memeriksa data supplier.',
            SupplierStatus::RevisionRequired => 'Ada data yang perlu diperbaiki sebelum diajukan ulang.',
            SupplierStatus::Approved => 'Pengajuan disetujui dan menunggu aktivasi.',
            SupplierStatus::Active => 'Supplier aktif. Lengkapi data yang masih kosong agar profil operasional tetap utuh.',
            SupplierStatus::Suspended => 'Supplier ditangguhkan. Data profil tetap dapat dipantau kelengkapannya.',
            SupplierStatus::Rejected => 'Tinjau catatan verifikasi atau hubungi tim.',
            SupplierStatus::Inactive => 'Supplier tidak aktif. Data profil tetap tersimpan.',
            default => 'Pantau status pendaftaran supplier.',
        };
    }

    private function statusColor(SupplierStatus $status): string
    {
        return match ($status) {
            SupplierStatus::Draft => 'gray',
            SupplierStatus::Submitted, SupplierStatus::UnderReview => 'warning',
            SupplierStatus::RevisionRequired, SupplierStatus::Rejected => 'danger',
            SupplierStatus::Approved, SupplierStatus::Active => 'success',
            SupplierStatus::Suspended, SupplierStatus::Inactive => 'danger',
            default => 'primary',
        };
    }
}
