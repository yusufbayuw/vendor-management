<?php

namespace Tests\Feature\Foundation;

use App\Enums\SupplierStatus;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuspendedSupplierPrivateFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake(VendorFileStorage::DISK);
    }

    public function test_suspended_supplier_invoice_file_requires_verified_active_owner_and_pivot(): void
    {
        [$supplier, $user, $invoice] = $this->invoiceWithPrivateFile();

        $supplier->forceFill(['status' => SupplierStatus::Suspended])->save();
        $user->forceFill(['phone_verified_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('files.invoice-files.show', $invoice))
            ->assertOk();

        $user->forceFill(['phone_verified_at' => null])->save();
        $this->actingAs($user)
            ->get(route('files.invoice-files.show', $invoice))
            ->assertForbidden();

        $user->forceFill(['phone_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($user)
            ->get(route('files.invoice-files.show', $invoice))
            ->assertForbidden();

        $user->forceFill(['is_active' => true])->save();
        $user->suppliers()->updateExistingPivot($supplier->getKey(), ['is_active' => false]);
        $this->actingAs($user)
            ->get(route('files.invoice-files.show', $invoice))
            ->assertForbidden();
    }

    public function test_other_supplier_is_denied_and_existing_onboarding_file_rule_is_unchanged(): void
    {
        [, $user, $invoice] = $this->invoiceWithPrivateFile();

        $otherSupplierUser = User::query()
            ->where('email', 'supplier.tani.admin@example.test')
            ->firstOrFail();

        $this->actingAs($otherSupplierUser)
            ->get(route('files.invoice-files.show', $invoice))
            ->assertForbidden();

        // The new verification requirement applies only to suspended history.
        // Keep existing private-document previews available during onboarding.
        $user->forceFill(['phone_verified_at' => null])->save();

        $this->actingAs($user)
            ->get(route('files.invoice-files.show', $invoice))
            ->assertOk();
    }

    /** @return array{Supplier, User, Invoice} */
    private function invoiceWithPrivateFile(): array
    {
        $supplier = Supplier::query()->where('code', 'SUP-AYAM')->firstOrFail();
        $user = User::query()->where('email', 'supplier.ayam.admin@example.test')->firstOrFail();
        $invoice = Invoice::query()->where('supplier_id', $supplier->getKey())->firstOrFail();

        $path = 'invoices/suspended-supplier-security.pdf';
        Storage::disk(VendorFileStorage::DISK)->put($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
        $invoice->forceFill(['invoice_file' => $path])->save();

        return [$supplier, $user, $invoice];
    }
}
