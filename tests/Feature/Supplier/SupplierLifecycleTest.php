<?php

namespace Tests\Feature\Supplier;

use App\Actions\Auth\ManuallyVerifyPhoneAction;
use App\Actions\Supplier\ApproveSupplierAction;
use App\Actions\Supplier\RequestSupplierRevisionAction;
use App\Actions\Supplier\StartSupplierReviewAction;
use App\Actions\Supplier\SubmitSupplierAction;
use App\Actions\Supplier\SuspendSupplierAction;
use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $owner->forceFill(['phone_verified_at' => now()])->save();
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
    }

    public function test_supplier_approval_is_blocked_until_owner_phone_is_manually_verified(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-MANUAL',
            'legal_name' => 'CV Pangan Manual',
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

        app(ManuallyVerifyPhoneAction::class)->execute(
            $owner,
            $reviewer,
            'Nomor dikonfirmasi melalui panggilan saat review supplier.',
        );

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
        $owner->forceFill(['phone_verified_at' => now()])->save();

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

    public function test_active_supplier_can_be_suspended_with_reason(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-003',
            'legal_name' => 'PT Supplier Aktif',
            'email' => 'aktif@example.test',
            'phone' => '08121111111',
            'status' => SupplierStatus::Active,
        ]);

        app(SuspendSupplierAction::class)->execute($supplier, 'Pelanggaran kualitas berulang.');

        $this->assertSame(SupplierStatus::Suspended, $supplier->refresh()->status);
        $this->assertSame('Pelanggaran kualitas berulang.', $supplier->suspension_reason);
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
