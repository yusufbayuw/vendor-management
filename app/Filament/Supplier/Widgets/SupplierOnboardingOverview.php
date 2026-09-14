<?php

namespace App\Filament\Supplier\Widgets;

use App\Enums\SupplierStatus;
use App\Filament\Supplier\Resources\BankAccounts\SupplierBankAccountResource;
use App\Filament\Supplier\Resources\Documents\SupplierDocumentResource;
use App\Filament\Supplier\Resources\Products\SupplierProductResource;
use App\Filament\Supplier\Resources\Profiles\SupplierProfileResource;
use App\Models\Supplier;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierOnboardingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->suppliers()
            ->wherePivot('is_active', true)
            ->whereIn('status', [
                SupplierStatus::Draft->value,
                SupplierStatus::Submitted->value,
                SupplierStatus::UnderReview->value,
                SupplierStatus::RevisionRequired->value,
                SupplierStatus::Approved->value,
                SupplierStatus::Rejected->value,
            ])
            ->exists();
    }

    protected function getStats(): array
    {
        $supplier = $this->supplier();

        if ($supplier === null) {
            return [];
        }

        $supplier->loadCount(['documents', 'bankAccounts', 'products']);

        $profileComplete = filled($supplier->legal_name)
            && filled($supplier->phone)
            && filled($supplier->supplier_type);
        $documentsComplete = $supplier->documents_count > 0;
        $bankComplete = $supplier->bank_accounts_count > 0;
        $productsComplete = $supplier->products_count > 0;

        $completed = collect([
            $profileComplete,
            $documentsComplete,
            $bankComplete,
            $productsComplete,
        ])->filter()->count();

        return [
            Stat::make('Status Pendaftaran', $supplier->status->label())
                ->description($this->statusDescription($supplier->status))
                ->icon('heroicon-o-identification')
                ->color($this->statusColor($supplier->status)),
            Stat::make('Progress Onboarding', $completed.'/4 langkah')
                ->description($this->nextStepDescription(
                    $supplier,
                    $profileComplete,
                    $documentsComplete,
                    $bankComplete,
                    $productsComplete,
                ))
                ->icon('heroicon-o-list-bullet')
                ->color($completed === 4 ? 'success' : 'warning'),
            Stat::make('Profil Supplier', $profileComplete ? 'Siap' : 'Perlu dilengkapi')
                ->description('Identitas dan kontak supplier')
                ->icon($profileComplete ? 'heroicon-o-check-circle' : 'heroicon-o-pencil-square')
                ->color($profileComplete ? 'success' : 'warning')
                ->url(SupplierProfileResource::getUrl('index')),
            Stat::make('Dokumen Legal', $supplier->documents_count.' dokumen')
                ->description($documentsComplete ? 'Dokumen sudah tersedia' : 'Unggah dokumen pendukung')
                ->icon($documentsComplete ? 'heroicon-o-check-circle' : 'heroicon-o-document-plus')
                ->color($documentsComplete ? 'success' : 'warning')
                ->url(SupplierDocumentResource::getUrl('index')),
            Stat::make('Rekening Bank', $supplier->bank_accounts_count.' rekening')
                ->description($bankComplete ? 'Rekening sudah tersedia' : 'Tambahkan rekening pembayaran')
                ->icon($bankComplete ? 'heroicon-o-check-circle' : 'heroicon-o-banknotes')
                ->color($bankComplete ? 'success' : 'warning')
                ->url(SupplierBankAccountResource::getUrl('index')),
            Stat::make('Katalog Produk', $supplier->products_count.' produk')
                ->description($productsComplete ? 'Katalog sudah tersedia' : 'Tambahkan produk yang ditawarkan')
                ->icon($productsComplete ? 'heroicon-o-check-circle' : 'heroicon-o-archive-box-arrow-down')
                ->color($productsComplete ? 'success' : 'warning')
                ->url(SupplierProductResource::getUrl('index')),
        ];
    }

    private function supplier(): ?Supplier
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return $user->suppliers()
            ->wherePivot('is_active', true)
            ->whereIn('status', [
                SupplierStatus::Draft->value,
                SupplierStatus::Submitted->value,
                SupplierStatus::UnderReview->value,
                SupplierStatus::RevisionRequired->value,
                SupplierStatus::Approved->value,
                SupplierStatus::Rejected->value,
            ])
            ->orderByPivot('is_owner', 'desc')
            ->orderBy('suppliers.id')
            ->first();
    }

    private function statusDescription(SupplierStatus $status): string
    {
        return match ($status) {
            SupplierStatus::Draft => 'Lengkapi data lalu ajukan verifikasi.',
            SupplierStatus::Submitted => 'Pengajuan sudah dikirim dan menunggu pemeriksaan.',
            SupplierStatus::UnderReview => 'Tim sedang memeriksa data supplier.',
            SupplierStatus::RevisionRequired => 'Ada data yang perlu diperbaiki sebelum diajukan ulang.',
            SupplierStatus::Approved => 'Pengajuan disetujui dan menunggu aktivasi.',
            SupplierStatus::Rejected => 'Pengajuan ditolak. Tinjau catatan verifikasi atau hubungi tim.',
            default => 'Pantau status pendaftaran supplier.',
        };
    }

    private function statusColor(SupplierStatus $status): string
    {
        return match ($status) {
            SupplierStatus::Draft => 'gray',
            SupplierStatus::Submitted, SupplierStatus::UnderReview => 'warning',
            SupplierStatus::RevisionRequired, SupplierStatus::Rejected => 'danger',
            SupplierStatus::Approved => 'success',
            default => 'primary',
        };
    }

    private function nextStepDescription(
        Supplier $supplier,
        bool $profileComplete,
        bool $documentsComplete,
        bool $bankComplete,
        bool $productsComplete,
    ): string {
        if (in_array($supplier->status, [SupplierStatus::Submitted, SupplierStatus::UnderReview], true)) {
            return 'Pengajuan sedang diproses. Anda akan mendapat notifikasi jika ada perubahan.';
        }

        if ($supplier->status === SupplierStatus::Approved) {
            return 'Data onboarding lengkap dan pengajuan telah disetujui.';
        }

        if (! $profileComplete) {
            return 'Berikutnya: lengkapi profil supplier.';
        }

        if (! $documentsComplete) {
            return 'Berikutnya: unggah dokumen legal.';
        }

        if (! $bankComplete) {
            return 'Berikutnya: tambahkan rekening bank.';
        }

        if (! $productsComplete) {
            return 'Berikutnya: lengkapi katalog produk.';
        }

        return $supplier->status === SupplierStatus::RevisionRequired
            ? 'Periksa catatan verifikasi lalu ajukan kembali dari Profil Perusahaan.'
            : 'Semua langkah dasar selesai. Ajukan verifikasi dari Profil Perusahaan.';
    }
}
