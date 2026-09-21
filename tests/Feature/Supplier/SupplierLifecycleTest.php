<?php

namespace Tests\Feature\Supplier;

use App\Actions\Supplier\ActivateSupplierWithOverrideAction;
use App\Actions\Supplier\ApproveSupplierAction;
use App\Actions\Supplier\RequestSupplierRevisionAction;
use App\Actions\Supplier\StartSupplierReviewAction;
use App\Actions\Supplier\SubmitSupplierAction;
use App\Actions\Supplier\SuspendSupplierAction;
use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierManagementMode;
use App\Enums\SupplierStatus;
use App\Models\PhoneVerificationCode;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use App\Services\Supplier\SupplierPortalAccessService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SupplierLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_can_move_from_draft_to_active_through_review(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-001',
            'legal_name' => 'PT Pangan Nusantara',
            'email' => 'vendor@example.test',
            'phone' => '08123456789',
        ]);
        $reviewer = User::factory()->create();
        $owner = User::factory()->create([
            'phone' => '081234567890',
        ]);
        $this->otpProof($owner);
        $supplier->users()->attach($owner->getKey(), [
            'is_owner' => true,
            'is_active' => true,
        ]);
        $this->verifiedDocument($supplier);

        app(SubmitSupplierAction::class)->execute($supplier);
        $this->assertSame(SupplierStatus::Submitted, $supplier->refresh()->status);

        app(StartSupplierReviewAction::class)->execute($supplier);
        $this->assertSame(SupplierStatus::UnderReview, $supplier->refresh()->status);

        app(ApproveSupplierAction::class)->execute($supplier, $reviewer);
        $this->assertSame(SupplierStatus::Active, $supplier->refresh()->status);
        $this->assertSame($reviewer->id, $supplier->verified_by);
        $this->assertTrue($supplier->approvalAttestation()->exists());
        $this->assertTrue(app(SupplierPortalAccessService::class)->isOperationallyEligible($supplier->refresh()));
    }

    public function test_admin_can_activate_internal_supplier_without_documents_or_portal_account(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-INTERNAL',
            'legal_name' => 'Supplier Existing Internal',
            'display_name' => 'Supplier Existing Internal',
            'management_mode' => SupplierManagementMode::AdminManaged,
            'email' => null,
            'phone' => null,
        ]);
        $admin = User::factory()->create();

        app(ActivateSupplierWithOverrideAction::class)->execute(
            $supplier,
            $admin,
            'Supplier existing sudah digunakan operasional; dokumen dan akun portal akan dilengkapi kemudian.',
            true,
            true,
        );

        $supplier->refresh();

        $this->assertSame(SupplierStatus::Active, $supplier->status);
        $this->assertSame(SupplierManagementMode::AdminManaged, $supplier->management_mode);
        $this->assertFalse($supplier->documents()->exists());
        $this->assertFalse($supplier->users()->exists());
        $this->assertTrue($supplier->approvalAttestation()->exists());
        $this->assertTrue(app(SupplierOperationalEligibilityService::class)->isOperationallyEligible($supplier));
        $this->assertTrue((bool) data_get($supplier->onboarding_exemptions, 'operational_override.documents.exempted'));
        $this->assertTrue((bool) data_get($supplier->onboarding_exemptions, 'operational_override.portal_identity.exempted'));
        $this->assertSame($admin->getKey(), data_get($supplier->onboarding_exemptions, 'operational_override.approved_by'));

        $unverifiedPortalUser = User::factory()->create([
            'phone' => '081299900001',
            'phone_verified_at' => null,
        ]);
        $supplier->users()->attach($unverifiedPortalUser->getKey(), [
            'is_owner' => true,
            'is_active' => true,
        ]);

        $this->assertFalse(app(SupplierPortalAccessService::class)->hasActiveSupplier($unverifiedPortalUser));
    }

    public function test_override_still_requires_explicit_exemption_for_missing_requirements(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-INTERNAL-GUARD',
            'legal_name' => 'Supplier Guard Internal',
            'management_mode' => SupplierManagementMode::AdminManaged,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dokumen');

        app(ActivateSupplierWithOverrideAction::class)->execute(
            $supplier,
            User::factory()->create(),
            'Operasional darurat.',
            false,
            true,
        );
    }

    public function test_supplier_approval_is_blocked_until_owner_phone_has_otp_proof(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-OTP',
            'legal_name' => 'CV Pangan OTP',
            'phone' => '081277700000',
        ]);
        $reviewer = User::factory()->create();
        $owner = User::factory()->create([
            'phone' => '081277700001',
            'phone_verified_at' => null,
        ]);
        $supplier->users()->attach($owner->getKey(), [
            'is_owner' => true,
            'is_active' => true,
        ]);
        $this->verifiedDocument($supplier);

        app(SubmitSupplierAction::class)->execute($supplier);
        app(StartSupplierReviewAction::class)->execute($supplier);

        try {
            app(ApproveSupplierAction::class)->execute($supplier, $reviewer);
            $this->fail('Approval should be blocked before phone verification.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Nomor HP PIC/owner supplier harus diverifikasi', $exception->getMessage());
        }

        $this->otpProof($owner);

        app(ApproveSupplierAction::class)->execute($supplier, $reviewer);

        $this->assertNotNull($owner->fresh()->phone_verified_at);
        $this->assertSame(SupplierStatus::Active, $supplier->refresh()->status);
    }

    public function test_supplier_approval_is_blocked_until_legal_documents_are_verified(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-DOC',
            'legal_name' => 'PT Supplier Dokumen',
            'phone' => '081277700010',
        ]);
        $reviewer = User::factory()->create();
        $owner = User::factory()->create([
            'phone' => '081277700011',
        ]);
        $this->otpProof($owner);

        $supplier->users()->attach($owner->getKey(), [
            'is_owner' => true,
            'is_active' => true,
        ]);

        $document = SupplierDocument::query()->create([
            'supplier_id' => $supplier->getKey(),
            'document_type' => 'nib',
            'document_number' => 'NIB-SUP-DOC',
            'file_path' => 'supplier-documents/nib-sup-doc.pdf',
            'status' => SupplierDocumentStatus::Uploaded,
        ]);

        app(SubmitSupplierAction::class)->execute($supplier);
        app(StartSupplierReviewAction::class)->execute($supplier);

        try {
            app(ApproveSupplierAction::class)->execute($supplier, $reviewer);
            $this->fail('Approval should be blocked before legal documents are verified.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('dokumen legal', strtolower($exception->getMessage()));
        }

        $document->forceFill([
            'status' => SupplierDocumentStatus::Verified,
            'verified_at' => now(),
            'verified_by' => $reviewer->getKey(),
        ])->save();

        app(ApproveSupplierAction::class)->execute($supplier, $reviewer);

        $this->assertSame(SupplierStatus::Active, $supplier->refresh()->status);
    }

    public function test_supplier_cannot_submit_verification_without_legal_document(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-NO-DOC',
            'legal_name' => 'Supplier Tanpa Dokumen',
            'phone' => '081234567899',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dokumen legal');

        app(SubmitSupplierAction::class)->execute($supplier);
    }

    public function test_supplier_without_email_can_be_submitted_using_phone(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-PHONE',
            'legal_name' => 'Warung Pangan Sejahtera',
            'email' => null,
            'phone' => '081234567890',
        ]);
        $this->verifiedDocument($supplier);

        app(SubmitSupplierAction::class)->execute($supplier);

        $this->assertSame(SupplierStatus::Submitted, $supplier->refresh()->status);
    }

    public function test_supplier_can_be_returned_for_revision_and_resubmitted(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-002',
            'legal_name' => 'CV Tani Jaya',
            'email' => 'tani@example.test',
            'phone' => '08120000000',
        ]);
        $this->verifiedDocument($supplier);

        app(SubmitSupplierAction::class)->execute($supplier);
        app(RequestSupplierRevisionAction::class)->execute($supplier, 'Dokumen NIB belum sesuai.');

        $this->assertSame(SupplierStatus::RevisionRequired, $supplier->refresh()->status);

        app(SubmitSupplierAction::class)->execute($supplier);

        $this->assertSame(SupplierStatus::Submitted, $supplier->refresh()->status);
    }

    public function test_setting_status_active_directly_does_not_bypass_operational_eligibility(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-BYPASS',
            'legal_name' => 'PT Bypass Ditolak',
            'phone' => '081244400000',
            'status' => SupplierStatus::Active,
            'verified_at' => now(),
            'verified_by' => User::factory()->create()->getKey(),
            'activated_at' => now(),
        ]);
        $owner = User::factory()->create(['phone' => '081244400001']);
        $this->otpProof($owner);
        $supplier->users()->attach($owner->getKey(), ['is_owner' => true, 'is_active' => true]);
        $this->verifiedDocument($supplier);

        $this->assertFalse(app(SupplierPortalAccessService::class)->isOperationallyEligible($supplier));
    }

    public function test_active_supplier_can_be_suspended_with_reason(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-003',
            'legal_name' => 'PT Supplier Aktif',
            'email' => 'aktif@example.test',
            'phone' => '08121111111',
            'status' => SupplierStatus::Active,
        ]);

        $supplier->approvalAttestation()->create([
            'approved_by' => User::factory()->create()->getKey(),
            'approved_at' => now(),
            'signature' => str_repeat('a', 64),
        ]);

        app(SuspendSupplierAction::class)->execute($supplier, 'Pelanggaran kualitas berulang.');

        $this->assertSame(SupplierStatus::Suspended, $supplier->refresh()->status);
        $this->assertSame('Pelanggaran kualitas berulang.', $supplier->suspension_reason);
        $this->assertFalse($supplier->approvalAttestation()->exists());
    }

    private function otpProof(User $user): PhoneVerificationCode
    {
        $verifiedAt = now();
        $user->forceFill(['phone_verified_at' => $verifiedAt])->save();

        return PhoneVerificationCode::query()->create([
            'user_id' => $user->getKey(),
            'phone' => $user->phone,
            'code_hash' => Hash::make('123456'),
            'attempt_count' => 1,
            'expires_at' => now()->addMinute(),
            'verified_at' => $verifiedAt,
            'sent_at' => now()->subMinute(),
        ]);
    }

    private function verifiedDocument(Supplier $supplier): SupplierDocument
    {
        return SupplierDocument::query()->create([
            'supplier_id' => $supplier->getKey(),
            'document_type' => 'nib',
            'document_number' => 'NIB-'.$supplier->code,
            'file_path' => 'supplier-documents/'.strtolower($supplier->code).'.pdf',
            'status' => SupplierDocumentStatus::Verified,
            'verified_at' => now(),
            'verified_by' => User::factory()->create()->getKey(),
        ]);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-004',
            'legal_name' => 'PT Invalid State',
            'email' => 'invalid@example.test',
            'phone' => '08122222222',
            'status' => SupplierStatus::Active,
        ]);

        $this->expectException(DomainException::class);

        app(SubmitSupplierAction::class)->execute($supplier);
    }
}
