<?php

namespace App\Services\Analytics;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PriceAnomalyAnalyticsService
{
    public const BASELINE_DAYS = 180;

    public const MIN_BASELINE_OBSERVATIONS = 5;

    public const ROBUST_Z_THRESHOLD = 3.5;

    /** @var array<int, array{status: string, is_anomaly: bool, baseline_count: int, median: float|null, mad: float|null, deviation_percentage: float|null, robust_z: float|null, reason: string}> */
    private array $analysisCache = [];

    public function __construct(private readonly UserAccessService $access) {}

    public function query(User $user): Builder
    {
        return PurchaseOrderItem::query()
            ->with([
                'purchaseOrder.kitchen',
                'purchaseOrder.supplier',
                'product.category',
                'unit',
            ])
            ->where('unit_price', '>', 0)
            ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query
                ->whereIn('sppg_kitchen_id', $this->access->accessibleKitchenIds($user))
                ->whereNotIn('status', $this->excludedStatuses()));
    }

    /**
     * @return array{status: string, is_anomaly: bool, baseline_count: int, median: float|null, mad: float|null, deviation_percentage: float|null, robust_z: float|null, reason: string}
     */
    public function analysis(PurchaseOrderItem $item): array
    {
        if (isset($this->analysisCache[$item->getKey()])) {
            return $this->analysisCache[$item->getKey()];
        }

        $baseline = $this->historicalBaseline($item);

        return $this->analysisCache[$item->getKey()] = $this->analyzeValues(
            (float) $item->unit_price,
            $baseline,
        );
    }

    /**
     * @param  Collection<int, float|int|string>  $baseline
     * @return array{status: string, is_anomaly: bool, baseline_count: int, median: float|null, mad: float|null, deviation_percentage: float|null, robust_z: float|null, reason: string}
     */
    public function analyzeValues(float $currentPrice, Collection $baseline): array
    {
        $values = $baseline
            ->map(fn ($value): float => (float) $value)
            ->filter(fn (float $value): bool => $value > 0)
            ->sort()
            ->values();

        $count = $values->count();
        $median = $this->median($values);

        if ($count < self::MIN_BASELINE_OBSERVATIONS || $median === null) {
            return [
                'status' => 'insufficient',
                'is_anomaly' => false,
                'baseline_count' => $count,
                'median' => $median,
                'mad' => null,
                'deviation_percentage' => $median !== null && $median > 0
                    ? round(100 * ($currentPrice - $median) / $median, 2)
                    : null,
                'robust_z' => null,
                'reason' => 'Baseline belum cukup: minimum '.self::MIN_BASELINE_OBSERVATIONS.' transaksi historis diperlukan.',
            ];
        }

        $absoluteDeviations = $values
            ->map(fn (float $value): float => abs($value - $median))
            ->sort()
            ->values();
        $mad = $this->median($absoluteDeviations) ?? 0.0;
        $deviationPercentage = round(100 * ($currentPrice - $median) / $median, 2);

        if ($mad <= 0.0001) {
            $isDifferent = abs($currentPrice - $median) > 0.01;

            if (! $isDifferent) {
                return [
                    'status' => 'normal',
                    'is_anomaly' => false,
                    'baseline_count' => $count,
                    'median' => round($median, 2),
                    'mad' => 0.0,
                    'deviation_percentage' => $deviationPercentage,
                    'robust_z' => null,
                    'reason' => 'Harga sama dengan baseline historis yang tidak memiliki dispersi.',
                ];
            }

            $status = $currentPrice > $median ? 'high' : 'low';

            return [
                'status' => $status,
                'is_anomaly' => true,
                'baseline_count' => $count,
                'median' => round($median, 2),
                'mad' => 0.0,
                'deviation_percentage' => $deviationPercentage,
                'robust_z' => null,
                'reason' => 'Harga berbeda dari baseline historis yang seluruh nilainya identik.',
            ];
        }

        $robustZ = 0.6745 * ($currentPrice - $median) / $mad;
        $isAnomaly = abs($robustZ) >= self::ROBUST_Z_THRESHOLD;
        $status = $isAnomaly
            ? ($robustZ > 0 ? 'high' : 'low')
            : 'normal';

        return [
            'status' => $status,
            'is_anomaly' => $isAnomaly,
            'baseline_count' => $count,
            'median' => round($median, 2),
            'mad' => round($mad, 2),
            'deviation_percentage' => $deviationPercentage,
            'robust_z' => round($robustZ, 2),
            'reason' => $isAnomaly
                ? 'Harga melewati threshold robust z-score '.self::ROBUST_Z_THRESHOLD.' terhadap baseline historis.'
                : 'Harga masih berada dalam variasi historis yang wajar.',
        ];
    }

    /** @return Collection<int, float> */
    public function historicalBaseline(PurchaseOrderItem $item): Collection
    {
        $item->loadMissing('purchaseOrder');
        $order = $item->purchaseOrder;

        if ($order === null || $order->order_date === null) {
            return collect();
        }

        $to = $order->order_date->copy();
        $from = $to->copy()->subDays(self::BASELINE_DAYS);

        return PurchaseOrderItem::query()
            ->whereKeyNot($item->getKey())
            ->where('product_id', $item->product_id)
            ->where('unit_id', $item->unit_id)
            ->where('unit_price', '>', 0)
            ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query
                ->where('sppg_kitchen_id', $order->sppg_kitchen_id)
                ->whereDate('order_date', '>=', $from->toDateString())
                ->whereDate('order_date', '<', $to->toDateString())
                ->whereNotIn('status', $this->excludedStatuses()))
            ->pluck('unit_price')
            ->map(fn ($value): float => (float) $value)
            ->values();
    }

    /** @param Collection<int, float> $values */
    private function median(Collection $values): ?float
    {
        $count = $values->count();

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values->get($middle);
        }

        return ((float) $values->get($middle - 1) + (float) $values->get($middle)) / 2;
    }

    /** @return array<string> */
    private function excludedStatuses(): array
    {
        return [
            PurchaseOrderStatus::Draft->value,
            PurchaseOrderStatus::PendingApproval->value,
            PurchaseOrderStatus::Approved->value,
            PurchaseOrderStatus::Cancelled->value,
        ];
    }
}
