<?php

namespace App\Services\Supplier;

use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Models\User;

class SupplierOperationalEligibilityService
{
    public const DOCUMENTS_EXEMPTION = 'documents';

    public const PORTAL_IDENTITY_EXEMPTION = 'portal_identity';

    public function __construct(
        private readonly SupplierApprovalAttestationService $attestations,
    ) {}

    public function isOperationallyEligible(Supplier $supplier): bool
    {
        if (
            $supplier->status !== SupplierStatus::Active
            || $supplier->verified_at === null
            || $supplier->verified_by === null
            || $supplier->activated_at === null
            || ! $this->attestations->isValid($supplier)
        ) {
            return false;
        }

        return ($this->documentsSatisfied($supplier) || $this->hasExemption($supplier, self::DOCUMENTS_EXEMPTION))
            && ($this->portalIdentitySatisfied($supplier) || $this->hasExemption($supplier, self::PORTAL_IDENTITY_EXEMPTION));
    }

    public function documentsSatisfied(Supplier $supplier): bool
    {
        $supplier->loadMissing('documents');

        if ($supplier->documents->isEmpty()) {
            return false;
        }

        return $supplier->documents->every(
            static fn ($document): bool => filled($document->file_path)
                && $document->status === SupplierDocumentStatus::Verified
                && $document->verified_at !== null
                && $document->verified_by !== null
                && ($document->expires_at === null || ! $document->expires_at->isPast()),
        );
    }

    public function portalIdentitySatisfied(Supplier $supplier): bool
    {
        $supplier->loadMissing('users.phoneVerificationCodes');

        $activeUsers = $supplier->users->filter(
            static fn (User $user): bool => (bool) $user->pivot?->is_active,
        );
        $owners = $activeUsers->filter(
            static fn (User $user): bool => (bool) $user->pivot?->is_owner,
        );
        $candidates = $owners->isNotEmpty() ? $owners : $activeUsers;

        return $candidates->contains(
            static fn (User $user): bool => $user->hasOtpVerifiedPhone(),
        );
    }

    public function hasExemption(Supplier $supplier, string $requirement): bool
    {
        $override = data_get($supplier->onboarding_exemptions, "operational_override.{$requirement}");

        return is_array($override)
            && ($override['exempted'] ?? false) === true
            && filled($override['reason'] ?? null)
            && filled($override['approved_by'] ?? null)
            && filled($override['approved_at'] ?? null);
    }
}
