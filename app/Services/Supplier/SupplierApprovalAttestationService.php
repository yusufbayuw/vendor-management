<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use App\Models\SupplierApprovalAttestation;
use App\Models\User;
use DomainException;

class SupplierApprovalAttestationService
{
    public function issue(Supplier $supplier, User $actor): SupplierApprovalAttestation
    {
        $supplier->refresh();

        if (
            $supplier->verified_at === null
            || $supplier->verified_by === null
            || $supplier->activated_at === null
        ) {
            throw new DomainException('Metadata approval supplier belum lengkap.');
        }

        return SupplierApprovalAttestation::query()->updateOrCreate(
            ['supplier_id' => $supplier->getKey()],
            [
                'approved_by' => $actor->getKey(),
                'approved_at' => $supplier->verified_at,
                'signature' => $this->signature($supplier),
            ],
        );
    }

    public function isValid(Supplier $supplier): bool
    {
        $attestation = $supplier->relationLoaded('approvalAttestation')
            ? $supplier->approvalAttestation
            : $supplier->approvalAttestation()->first();

        if (
            $attestation === null
            || $supplier->verified_at === null
            || $supplier->verified_by === null
            || $supplier->activated_at === null
            || (int) $attestation->approved_by !== (int) $supplier->verified_by
            || ! $attestation->approved_at?->equalTo($supplier->verified_at)
        ) {
            return false;
        }

        return hash_equals($attestation->signature, $this->signature($supplier));
    }

    private function signature(Supplier $supplier): string
    {
        $key = (string) config('supplier-security.approval_signing_key');

        if ($key === '') {
            throw new DomainException('SUPPLIER_APPROVAL_SIGNING_KEY / APP_KEY belum dikonfigurasi.');
        }

        $payload = implode('|', [
            (string) $supplier->getKey(),
            (string) $supplier->code,
            (string) $supplier->status->value,
            (string) $supplier->verified_by,
            $supplier->verified_at?->toISOString() ?? '',
            $supplier->activated_at?->toISOString() ?? '',
        ]);

        return hash_hmac('sha256', $payload, $key);
    }
}
