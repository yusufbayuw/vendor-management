<?php

namespace App\Actions\Payment;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SupplierBankAccount;
use App\Models\User;
use App\Services\Documents\DocumentNumberService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreatePaymentAction
{
    public function __construct(private readonly DocumentNumberService $documentNumbers) {}

    public function execute(
        Invoice $invoice,
        float $amount,
        PaymentMethod $method,
        User $actor,
        Carbon|string|null $paymentDate = null,
        ?string $sourceBankName = null,
        ?string $referenceNumber = null,
        ?SupplierBankAccount $destinationAccount = null,
        ?string $notes = null,
    ): Payment {
        if ($amount <= 0) {
            throw new DomainException('Nilai pembayaran harus lebih dari nol.');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $actor, $paymentDate, $sourceBankName, $referenceNumber, $destinationAccount, $notes): Payment {
            $invoice = Invoice::query()->with(['kitchen', 'supplier'])->lockForUpdate()->findOrFail($invoice->getKey());

            if (! in_array($invoice->status, [InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid], true)) {
                throw new DomainException('Pembayaran hanya dapat dibuat untuk invoice yang sudah disetujui.');
            }

            if ($destinationAccount !== null && $destinationAccount->supplier_id !== $invoice->supplier_id) {
                throw new DomainException('Rekening tujuan tidak dimiliki supplier pada invoice ini.');
            }

            $committed = (float) Payment::query()
                ->where('invoice_id', $invoice->getKey())
                ->whereNotIn('status', [PaymentStatus::Rejected->value, PaymentStatus::Cancelled->value])
                ->sum('amount');
            $outstanding = max(0, (float) $invoice->payable_amount - $committed);

            if ($amount - $outstanding > 0.01) {
                throw new DomainException(sprintf('Pembayaran melebihi outstanding invoice. Sisa: %.2f.', $outstanding));
            }

            return Payment::query()->create([
                'number' => $this->documentNumbers->next('PAY', $invoice->kitchen),
                'invoice_id' => $invoice->getKey(),
                'payment_date' => $paymentDate === null ? today() : Carbon::parse($paymentDate),
                'amount' => $amount,
                'payment_method' => $method,
                'source_bank_name' => $sourceBankName,
                'destination_bank_name' => $destinationAccount?->bank_name,
                'destination_account_number' => $destinationAccount?->account_number,
                'destination_account_holder' => $destinationAccount?->account_holder,
                'reference_number' => $referenceNumber,
                'status' => PaymentStatus::Draft,
                'created_by' => $actor->getKey(),
                'notes' => $notes,
            ]);
        }, 3);
    }
}
