<?php

namespace App\Services\Reporting;

use App\Enums\DiscrepancyStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\FulfillmentDiscrepancy;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProcurementReportService
{
    public function __construct(private readonly UserAccessService $access) {}

    /** @return array<string, int|float> */
    public function summary(User $user, Carbon|string|null $from = null, Carbon|string|null $to = null): array
    {
        [$start, $end] = $this->period($from, $to);
        $kitchenIds = $this->kitchenIds($user);

        $orders = PurchaseOrder::query()
            ->whereIn('sppg_kitchen_id', $kitchenIds)
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()]);

        $invoices = Invoice::query()
            ->whereIn('sppg_kitchen_id', $kitchenIds)
            ->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()]);

        $invoiceModels = (clone $invoices)
            ->whereIn('status', [InvoiceStatus::Approved->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value])
            ->withSum([
                'payments as verified_paid_amount' => fn ($query) => $query->where('status', PaymentStatus::Verified->value),
            ], 'amount')
            ->get();

        return [
            'po_count' => (clone $orders)->count(),
            'po_value' => (float) (clone $orders)->sum('total_amount'),
            'supplier_count' => (clone $orders)->distinct('supplier_id')->count('supplier_id'),
            'invoice_count' => (clone $invoices)->count(),
            'invoice_value' => (float) (clone $invoices)->sum('payable_amount'),
            'verified_payment_value' => (float) $invoiceModels->sum('verified_paid_amount'),
            'outstanding_value' => (float) $invoiceModels->sum(
                fn (Invoice $invoice): float => max(0, (float) $invoice->payable_amount - (float) ($invoice->verified_paid_amount ?? 0)),
            ),
            'open_discrepancy_count' => FulfillmentDiscrepancy::query()
                ->whereHas('purchaseOrder', fn (Builder $query) => $query
                    ->whereIn('sppg_kitchen_id', $kitchenIds)
                    ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()]))
                ->where('status', DiscrepancyStatus::Open->value)
                ->count(),
        ];
    }

    /** @return Collection<int, PurchaseOrder> */
    public function purchaseOrders(User $user, Carbon|string|null $from = null, Carbon|string|null $to = null): Collection
    {
        [$start, $end] = $this->period($from, $to);

        return PurchaseOrder::query()
            ->with(['supplier', 'kitchen'])
            ->whereIn('sppg_kitchen_id', $this->kitchenIds($user))
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('order_date')
            ->orderBy('number')
            ->get();
    }

    /** @return Collection<int, int> */
    private function kitchenIds(User $user): Collection
    {
        return $this->access->applyKitchenScope(SppgKitchen::query(), $user)->pluck('id');
    }

    /** @return array{Carbon, Carbon} */
    private function period(Carbon|string|null $from, Carbon|string|null $to): array
    {
        $end = $to === null ? today() : Carbon::parse($to)->endOfDay();
        $start = $from === null ? $end->copy()->subDays(29)->startOfDay() : Carbon::parse($from)->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }
}
