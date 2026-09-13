<?php

namespace App\Services\Analytics;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;

class FinanceAnalyticsService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function query(User $user): Builder
    {
        return Invoice::query()
            ->with([
                'supplier',
                'kitchen',
                'purchaseOrder',
                'payments',
            ])
            ->withSum([
                'payments as verified_paid_amount' => fn (Builder $query): Builder => $query
                    ->where('status', PaymentStatus::Verified->value),
            ], 'amount')
            ->whereIn('sppg_kitchen_id', $this->access->accessibleKitchenIds($user));
    }

    public function verifiedPaid(Invoice $invoice): float
    {
        if ($invoice->getAttribute('verified_paid_amount') !== null) {
            return (float) $invoice->getAttribute('verified_paid_amount');
        }

        return (float) $invoice->payments
            ->where('status', PaymentStatus::Verified)
            ->sum('amount');
    }

    public function outstanding(Invoice $invoice): float
    {
        if (in_array($invoice->status, [InvoiceStatus::Rejected, InvoiceStatus::Cancelled], true)) {
            return 0;
        }

        return max(0, (float) $invoice->payable_amount - $this->verifiedPaid($invoice));
    }

    public function overdueDays(Invoice $invoice): int
    {
        if ($invoice->due_date === null || $this->outstanding($invoice) <= 0 || ! $invoice->due_date->isBefore(today())) {
            return 0;
        }

        return (int) floor($invoice->due_date->diffInDays(today()));
    }

    public function agingBucket(Invoice $invoice): string
    {
        if (in_array($invoice->status, [InvoiceStatus::Rejected, InvoiceStatus::Cancelled], true)) {
            return 'Non-payable';
        }

        if ($this->outstanding($invoice) <= 0) {
            return 'Paid';
        }

        if ($invoice->due_date === null || ! $invoice->due_date->isBefore(today())) {
            return 'Current';
        }

        return match (true) {
            $this->overdueDays($invoice) <= 7 => '1–7 hari',
            $this->overdueDays($invoice) <= 14 => '8–14 hari',
            $this->overdueDays($invoice) <= 30 => '15–30 hari',
            default => '>30 hari',
        };
    }

    public function paymentLeadDays(Invoice $invoice): ?float
    {
        if ($invoice->approved_at === null) {
            return null;
        }

        $latestVerifiedAt = $invoice->payments
            ->where('status', PaymentStatus::Verified)
            ->pluck('verified_at')
            ->filter()
            ->max();

        if ($latestVerifiedAt === null) {
            return null;
        }

        return round($invoice->approved_at->diffInMinutes($latestVerifiedAt) / 1440, 1);
    }
}
