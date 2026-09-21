<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierManagementMode;
use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Supplier\SupplierApprovalAttestationService;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ActivateSupplierWithOverrideAction
{
    public function __construct(
        private readonly SupplierOperationalEligibilityService $eligibility,
        private readonly SupplierApprovalAttestationService $attestations,
    ) {}

    public function execute(
        Supplier $supplier,
        User $actor,
        string $reason,
        bool $bypassDocuments = true,
        bool $bypassPortalIdentity = true,
    ): Supplier {
        if (! in_array($supplier->status, [
            SupplierStatus::Draft,
            SupplierStatus::Submitted,
            SupplierStatus::UnderReview,
            SupplierStatus::RevisionRequired,
        ], true)) {
            throw new DomainException('Override aktivasi hanya dapat dilakukan pada supplier yang masih dalam proses onboarding.');
        }

        if (blank($supplier->legal_name)) {
            throw new DomainException('Nama legal supplier wajib tersedia sebelum supplier dapat diaktifkan.');
        }

        if (blank($reason)) {
            throw new DomainException('Alasan override wajib diisi.');
        }

        $documentsSatisfied = $this->eligibility->documentsSatisfied($supplier);
        $portalIdentitySatisfied = $this->eligibility->portalIdentitySatisfied($supplier);

        if (! $documentsSatisfied && ! $bypassDocuments) {
            throw new DomainException('Dokumen supplier belum memenuhi syarat. Aktifkan bypass dokumen atau lengkapi verifikasi dokumen.');
        }

        if (! $portalIdentitySatisfied && ! $bypassPortalIdentity) {
            throw new DomainException('PIC/akun supplier belum terverifikasi. Aktifkan bypass akun/PIC atau lengkapi verifikasi portal.');
        }

        return DB::transaction(function () use (
            $supplier,
            $actor,
            $reason,
            $documentsSatisfied,
            $portalIdentitySatisfied,
            $bypassDocuments,
            $bypassPortalIdentity,
        ): Supplier {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->getKey());
            $exemptions = $supplier->onboarding_exemptions ?? [];
            $approvedAt = now()->toISOString();

            $override = [
                'reason' => $reason,
                'approved_by' => $actor->getKey(),
                'approved_at' => $approvedAt,
            ];

            if (! $documentsSatisfied && $bypassDocuments) {
                $override[SupplierOperationalEligibilityService::DOCUMENTS_EXEMPTION] = [
                    'exempted' => true,
                    'reason' => $reason,
                    'approved_by' => $actor->getKey(),
                    'approved_at' => $approvedAt,
                ];
            }

            if (! $portalIdentitySatisfied && $bypassPortalIdentity) {
                $override[SupplierOperationalEligibilityService::PORTAL_IDENTITY_EXEMPTION] = [
                    'exempted' => true,
                    'reason' => $reason,
                    'approved_by' => $actor->getKey(),
                    'approved_at' => $approvedAt,
                ];
            }

            $exemptions['operational_override'] = $override;

            $supplier->forceFill([
                'management_mode' => $portalIdentitySatisfied
                    ? $supplier->management_mode
                    : SupplierManagementMode::AdminManaged,
                'onboarding_exemptions' => $exemptions,
                'status' => SupplierStatus::Active,
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
                'activated_at' => now(),
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            $this->attestations->issue($supplier, $actor);

            return $supplier->refresh();
        });
    }
}
