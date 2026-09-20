<?php

namespace App\Filament\Supplier\Widgets;

use App\Enums\SupplierStatus;
use App\Services\Supplier\SupplierPortalAccessService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierAccessStatus extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $supplier = app(SupplierPortalAccessService::class)->currentSupplier(auth()->user());

        return $supplier !== null && $supplier->status !== SupplierStatus::Active;
    }

    protected function getStats(): array
    {
        $supplier = app(SupplierPortalAccessService::class)->currentSupplier(auth()->user());

        if ($supplier === null) {
            return [];
        }

        return [
            Stat::make('Akses Portal', 'Onboarding / Terbatas')
                ->description('Akun dapat login untuk melengkapi data. Purchase Order, pengiriman, dan invoice terkunci sampai supplier selesai diverifikasi dan aktif.')
                ->icon('heroicon-o-lock-closed')
                ->color('warning'),
            Stat::make('Status Verifikasi', $supplier->status->label())
                ->description($this->description($supplier->status))
                ->icon('heroicon-o-shield-check')
                ->color($this->color($supplier->status)),
        ];
    }

    private function description(SupplierStatus $status): string
    {
        return match ($status) {
            SupplierStatus::Draft => 'Lengkapi profil dan dokumen legal, lalu ajukan verifikasi.',
            SupplierStatus::Submitted => 'Pengajuan sudah diterima dan menunggu pemeriksaan petugas.',
            SupplierStatus::UnderReview => 'Petugas sedang memeriksa profil dan dokumen legal supplier.',
            SupplierStatus::RevisionRequired => 'Ada data atau dokumen yang perlu diperbaiki sebelum verifikasi dilanjutkan.',
            SupplierStatus::Approved => 'Supplier sudah disetujui dan menunggu aktivasi.',
            SupplierStatus::Suspended => 'Akses transaksi dihentikan selama supplier ditangguhkan.',
            SupplierStatus::Rejected => 'Pengajuan belum dapat diterima. Tinjau catatan petugas dan perbaiki data yang diperlukan.',
            SupplierStatus::Inactive => 'Supplier tidak aktif. Hubungi pengelola jika status perlu diaktifkan kembali.',
            SupplierStatus::Active => 'Supplier aktif.',
        };
    }

    private function color(SupplierStatus $status): string
    {
        return match ($status) {
            SupplierStatus::Draft, SupplierStatus::Submitted, SupplierStatus::UnderReview, SupplierStatus::Approved => 'warning',
            SupplierStatus::RevisionRequired, SupplierStatus::Suspended, SupplierStatus::Rejected, SupplierStatus::Inactive => 'danger',
            SupplierStatus::Active => 'success',
        };
    }
}
