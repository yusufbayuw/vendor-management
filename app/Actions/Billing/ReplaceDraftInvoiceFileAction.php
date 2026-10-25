<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\SystemPermission;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use App\Services\Supplier\SupplierPortalAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReplaceDraftInvoiceFileAction
{
    public function __construct(
        private readonly SupplierPortalAccessService $portal,
        private readonly VendorFileStorage $files,
    ) {}

    public function execute(Invoice $invoice, User $actor, string $newPath): Invoice
    {
        if (blank($newPath)) {
            throw new DomainException('File invoice wajib tersedia.');
        }

        return DB::transaction(function () use ($invoice, $actor, $newPath): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new DomainException('File invoice hanya boleh diubah saat invoice masih Draft.');
            }

            if (! $actor->is_active || ! $actor->can(SystemPermission::InvoiceSubmit->value)
                || ! in_array((int) $invoice->supplier_id, $this->portal->activeSupplierIds($actor), true)) {
                throw new DomainException('User tidak berwenang mengubah file invoice supplier ini.');
            }

            $this->files->assertSafeDocument($newPath);

            $oldPath = $invoice->invoice_file;
            if ($oldPath === $newPath) {
                return $invoice;
            }

            $invoice->forceFill(['invoice_file' => $newPath])->save();

            // Delete only after durable commit; rollback/rejected mutations
            // must preserve the original invoice evidence.
            if (filled($oldPath)) {
                DB::afterCommit(function () use ($oldPath): void {
                    try {
                        if (! Invoice::query()->where('invoice_file', $oldPath)->exists()) {
                            $this->files->delete($oldPath);
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
            }

            return $invoice->refresh();
        }, 3);
    }
}
