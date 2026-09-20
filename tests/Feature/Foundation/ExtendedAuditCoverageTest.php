<?php

namespace Tests\Feature\Foundation;

use App\Actions\Supplier\StartSupplierReviewAction;
use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Models\AuditLog;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtendedAuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_transaction_item_changes_are_audited(): void
    {
        $item = PurchaseRequestItem::query()->firstOrFail();

        AuditLog::query()
            ->where('auditable_type', $item->getMorphClass())
            ->where('auditable_id', $item->getKey())
            ->delete();

        $item->update(['notes' => 'Perubahan item untuk pengujian audit.']);

        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', $item->getMorphClass())
            ->where('auditable_id', $item->getKey())
            ->where('event', 'updated')
            ->exists());
    }

    public function test_supplier_review_updates_documents_through_model_events(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-AUDIT-REVIEW',
            'legal_name' => 'Supplier Audit Review',
            'display_name' => 'Supplier Audit Review',
            'email' => 'supplier.audit.review@example.test',
            'phone' => '081234560001',
            'status' => SupplierStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $document = SupplierDocument::query()->create([
            'supplier_id' => $supplier->getKey(),
            'document_type' => 'NIB',
            'document_number' => 'NIB-AUDIT-001',
            'file_path' => 'supplier-documents/audit-review.pdf',
            'status' => SupplierDocumentStatus::Uploaded,
        ]);

        AuditLog::query()
            ->where('auditable_type', $document->getMorphClass())
            ->where('auditable_id', $document->getKey())
            ->delete();

        app(StartSupplierReviewAction::class)->execute($supplier);

        $document->refresh();

        $this->assertSame(SupplierDocumentStatus::UnderReview, $document->status);
        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', $document->getMorphClass())
            ->where('auditable_id', $document->getKey())
            ->where('event', 'updated')
            ->exists());
    }
}
