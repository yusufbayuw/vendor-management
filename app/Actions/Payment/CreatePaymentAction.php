<?php

namespace App\Actions\Payment;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SupplierBankAccount;
use App\Models\User;
use App\Enums\SystemPermission;
use App\Services\Access\PaymentActionAuthorizationService;
use App\Services\Documents\DocumentNumberService;
use App\Support\MoneyMinorUnits;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreatePaymentAction
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly PaymentActionAuthorizationService $authorization,
    ) {}

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
        if (MoneyMinorUnits::fromDecimal($amount) <= 0) {
            throw new DomainException('Nilai pembayaran harus lebih dari nol.');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $actor, $paymentDate, $sourceBankName, $referenceNumber, $destinationAccount, $notes): Payment {
            $invoice = Invoice::query()->with(['kitchen', 'supplier'])->lockForUpdate()->findOrFail($invoice->getKey());

            $this->authorization->assertForInvoice($invoice, $actor, SystemPermission::PaymentCreate);

            if (! in_array($invoice->status, [InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid], true)) {
                throw new DomainException('Pembayaran hanya dapat dibuat untuk invoice yang sudah disetujui.');
            }

            if ($destinationAccount !== null) {
                $destinationAccount = SupplierBankAccount::query()
                    ->lockForUpdate()
                    ->findOrFail($destinationAccount->getKey());

                if ($destinationAccount->supplier_id !== $invoice->supplier_id) {
                    throw new DomainException('Rekening tujuan tidak dimiliki supplier pada invoice ini.');
                }

                if ($destinationAccount->verification_status !== VerificationStatus::Verified) {
                    throw new DomainException('Rekening tujuan belum terverifikasi.');
                }
            }

            if ($method === PaymentMethod::BankTransfer && $destinationAccount === null) {
                $destinationAccount = SupplierBankAccount::query()
                    ->where('supplier_id', $invoice->supplier_id)
                    ->where('verification_status', VerificationStatus::Verified->value)
                    ->where('is_primary', true)
                    ->lockForUpdate()
                    ->first();

                if ($destinationAccount === null) {
                    throw new DomainException('Transfer bank memerlukan rekening supplier terverifikasi.');
                }
            }

            $committed = Payment::query()
                ->where('invoice_id', $invoice->getKey())
                ->whereNotIn('status', [PaymentStatus::Rejected->value, PaymentStatus::Cancelled->value])
                ->sum('amount');
            $outstandingMinor = max(0, MoneyMinorUnits::fromDecimal($invoice->payable_amount)
                - MoneyMinorUnits::fromDecimal($committed));

            if (MoneyMinorUnits::fromDecimal($amount) > $outstandingMinor) {
                throw new DomainException(sprintf('Pembayaran melebihi outstanding invoice. Sisa: %.2f.', $outstandingMinor / 100));
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
