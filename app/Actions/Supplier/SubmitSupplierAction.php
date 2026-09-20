<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Services\Supplier\SupplierOnboardingService;
use DomainException;

class SubmitSupplierAction
{
    public function execute(Supplier $supplier): Supplier
    {
        if (! in_array($supplier->status, [SupplierStatus::Draft, SupplierStatus::RevisionRequired], true)) {
            throw new DomainException('Supplier hanya dapat diajukan dari status draft atau perlu perbaikan.');
        }

        $summary = app(SupplierOnboardingService::class)->summary($supplier);

        if (! $summary['complete']) {
            $missing = collect($summary['missing'])->take(4)->implode(', ');

            throw new DomainException(
                'Data onboarding belum lengkap ('.$summary['percentage'].'%). Lengkapi: '.$missing.'.',
            );
        }

        $supplier->forceFill([
            'status' => SupplierStatus::Submitted,
            'submitted_at' => now(),
        ])->save();

        return $supplier->refresh();
    }
}
