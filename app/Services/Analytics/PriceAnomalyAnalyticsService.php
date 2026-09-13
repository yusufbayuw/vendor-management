<?php

namespace App\Services\Analytics;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\Support\RobustOutlierDetector;
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

        return $this->analysisCache[$item->getKey()] = $this->analyzeValues(
            (float) $item->unit_price,
            $this->historicalBaseline($item),
        );
    }

    /**
     * @param  Collection<int, float|int|string>  $baseline
     * @return array{status: string, is_anomaly: bool, baseline_count: int, median: float|null, mad: float|null, deviation_percentage: float|null, robust_z: float|null, reason: string}
     */
    public function analyzeValues(float $currentPrice, Collection $baseline): array
    {
        $result = RobustOutlierDetector::analyze(
            $currentPrice,
            $baseline->filter(fn ($value): bool => (float) $value > 0)->values(),
            self::MIN_BASELINE_OBSERVATIONS,
            self::ROBUST_Z_THRESHOLD,
        );

        return [
            'status' => $result['status'],
            'is_anomaly' => $result['is_anomaly'],
            'baseline_count' => $result['baseline_count'],
            'median' => $result['median'],
            'mad' => $result['mad'],
            'deviation_percentage' => $result['deviation_percentage'],
            'robust_z' => $result['robust_z'],
            'reason' => match ($result['reason_code']) {
                'insufficient_baseline' => 'Baseline belum cukup: minimum '.self::MIN_BASELINE_OBSERVATIONS.' transaksi historis diperlukan.',
                'zero_dispersion_equal' => 'Harga sama dengan baseline historis yang tidak memiliki dispersi.',
                'zero_dispersion_changed' => 'Harga berbeda dari baseline historis yang seluruh nilainya identik.',
                'threshold_exceeded' => 'Harga melewati threshold robust z-score '.self::ROBUST_Z_THRESHOLD.' terhadap baseline historis.',
                default => 'Harga masih berada dalam variasi historis yang wajar.',
            },
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
