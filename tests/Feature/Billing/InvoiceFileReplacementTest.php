<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ReplaceDraftInvoiceFileAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceFileReplacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake(VendorFileStorage::DISK);
    }

    public function test_supplier_owner_can_replace_draft_file_but_not_after_submission_or_approval(): void
    {
        [$invoice, $actor] = $this->draftAyamInvoice();
        $disk = Storage::disk(VendorFileStorage::DISK);
        $disk->put('invoices/original.pdf', "%PDF-1.4\n% invoice evidence\n");
        $disk->put('invoices/revised.pdf', "%PDF-1.4\n% invoice revised evidence\n");
        $disk->put('invoices/stale.pdf', "%PDF-1.4\n% stale evidence\n");

        app(ReplaceDraftInvoiceFileAction::class)->execute($invoice, $actor, 'invoices/revised.pdf');

        $this->assertSame('invoices/revised.pdf', $invoice->refresh()->invoice_file);

        foreach ([InvoiceStatus::Submitted, InvoiceStatus::UnderReview, InvoiceStatus::Approved] as $status) {
            $invoice->forceFill(['status' => $status])->save();

            try {
                app(ReplaceDraftInvoiceFileAction::class)->execute($invoice, $actor, 'invoices/stale.pdf');
                $this->fail('A stale upload must never replace a non-draft invoice file.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('Draft', $exception->getMessage());
            }

            $this->assertSame('invoices/revised.pdf', $invoice->refresh()->invoice_file);
            $disk->assertExists('invoices/revised.pdf');
        }

        // A properly authorized Return-for-Revision moves it back to Draft;
        // that existing workflow can safely reopen the upload action.
        $invoice->forceFill(['status' => InvoiceStatus::Draft])->save();
        app(ReplaceDraftInvoiceFileAction::class)->execute($invoice, $actor, 'invoices/stale.pdf');
        $this->assertSame('invoices/stale.pdf', $invoice->refresh()->invoice_file);
    }

    public function test_other_supplier_and_unverified_or_deactivated_user_cannot_replace_draft_evidence(): void
    {
        [$invoice, $ayamActor] = $this->draftAyamInvoice();
        $taniActor = User::query()->where('email', 'supplier.tani.admin@example.test')->firstOrFail();
        $disk = Storage::disk(VendorFileStorage::DISK);
        $disk->put('invoices/original.pdf', "%PDF-1.4\n% original\n");
        $disk->put('invoices/attempt.pdf', "%PDF-1.4\n% blocked\n");

        try {
            app(ReplaceDraftInvoiceFileAction::class)->execute($invoice, $taniActor, 'invoices/attempt.pdf');
            $this->fail('Unrelated supplier identity must be denied.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('berwenang', $exception->getMessage());
        }

        $ayamActor->forceFill(['phone_verified_at' => null])->save();
        try {
            app(ReplaceDraftInvoiceFileAction::class)->execute($invoice, $ayamActor, 'invoices/attempt.pdf');
            $this->fail('Unverified phone must be denied.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('berwenang', $exception->getMessage());
        }

        $ayamActor->forceFill(['is_active' => false])->save();
        try {
            app(ReplaceDraftInvoiceFileAction::class)->execute($invoice, $ayamActor, 'invoices/attempt.pdf');
            $this->fail('Inactive supplier actor must be denied.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('berwenang', $exception->getMessage());
        }

        $this->assertSame('invoices/original.pdf', $invoice->refresh()->invoice_file);
        $disk->assertExists('invoices/original.pdf');
    }

    /** @return array{Invoice, User} */
    private function draftAyamInvoice(): array
    {
        $supplier = Supplier::query()->where('code', 'SUP-AYAM')->firstOrFail();
        $invoice = Invoice::query()->where('supplier_id', $supplier->getKey())->firstOrFail();
        $invoice->forceFill(['status' => InvoiceStatus::Draft, 'invoice_file' => 'invoices/original.pdf'])->save();

        $actor = User::query()->where('email', 'supplier.ayam.admin@example.test')->firstOrFail();

        return [$invoice->refresh(), $actor];
    }
}
