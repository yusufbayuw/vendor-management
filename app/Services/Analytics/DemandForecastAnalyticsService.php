<?php

namespace App\Services\Analytics;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DemandForecastAnalyticsService
{
    public const LOOKBACK_WEEKS = 26;

    public const MIN_OBSERVATIONS = 8;

    public const MIN_COVERAGE_PERCENTAGE = 70.0;

    public const MAX_STALENESS_WEEKS = 2;

    public const HORIZON_WEEKS = 4;

    /** @var array<string, array<string, mixed>> */
    private array $forecastCache = [];

    public function __construct(private readonly UserAccessService $access) {}

    public function query(User $user): Builder
    {
        $kitchenIds = $this->access->accessibleKitchenIds($user);
        $statuses = $this->eligibleStatuses();

        $representativeIds = DB::table('purchase_request_items as pri')
            ->join('purchase_requests as pr', 'pr.id', '=', 'pri.purchase_request_id')
            ->whereIn('pr.sppg_kitchen_id', $kitchenIds)
            ->whereIn('pr.status', $statuses)
            ->whereNotNull('pr.period_start')
            ->groupBy('pr.sppg_kitchen_id', 'pri.product_id', 'pri.unit_id')
            ->selectRaw('MAX(pri.id)');

        return PurchaseRequestItem::query()
            ->with([
                'purchaseRequest.kitchen.organization',
                'product.category',
                'unit',
            ])
            ->whereIn('purchase_request_items.id', $representativeIds);
    }

    /**
     * @return array{status: string, observation_count: int, span_weeks: int, coverage_percentage: float, staleness_weeks: int|null, median_weekly_demand: float|null, slope_per_week: float|null, residual_mad: float|null, variability_band: float|null, trend: string, last_observed_week: string|null, reason: string, forecasts: array<int, array{week_start: string, quantity: float, lower: float, upper: float}>}
     */
    public function forecast(PurchaseRequestItem $representative): array
    {
        $representative->loadMissing('purchaseRequest');
        $request = $representative->purchaseRequest;

        if ($request === null) {
            return $this->insufficientForecast('Purchase Request tidak tersedia untuk kombinasi demand ini.');
        }

        $cacheKey = implode(':', [
            $request->sppg_kitchen_id,
            $representative->product_id,
            $representative->unit_id,
        ]);

        if (isset($this->forecastCache[$cacheKey])) {
            return $this->forecastCache[$cacheKey];
        }

        $forecastStart = today()->startOfWeek()->addWeek();

        return $this->forecastCache[$cacheKey] = $this->forecastSeries(
            $this->weeklySeries(
                (int) $request->sppg_kitchen_id,
                (int) $representative->product_id,
                (int) $representative->unit_id,
                $forecastStart,
            ),
            $forecastStart,
        );
    }

    /**
     * @return Collection<int, array{week_start: string, quantity: float}>
     */
    public function weeklySeries(
        int $kitchenId,
        int $productId,
        int $unitId,
        ?CarbonInterface $forecastStart = null,
    ): Collection {
        $forecastStart ??= today()->startOfWeek()->addWeek();
        $historyEnd = $forecastStart->copy()->subWeek()->startOfWeek();
        $historyStart = $historyEnd->copy()->subWeeks(self::LOOKBACK_WEEKS - 1);

        return PurchaseRequestItem::query()
            ->with('purchaseRequest')
            ->where('product_id', $productId)
            ->where('unit_id', $unitId)
            ->whereHas('purchaseRequest', fn (Builder $query): Builder => $query
                ->where('sppg_kitchen_id', $kitchenId)
                ->whereIn('status', $this->eligibleStatuses())
                ->whereNotNull('period_start')
                ->whereDate('period_start', '>=', $historyStart->toDateString())
                ->whereDate('period_start', '<=', $historyEnd->toDateString()))
            ->get()
            ->groupBy(fn (PurchaseRequestItem $item): string => $item->purchaseRequest->period_start
                ->copy()
                ->startOfWeek()
                ->toDateString())
            ->map(fn (Collection $items, string $week): array => [
                'week_start' => $week,
                'quantity' => round((float) $items->sum(fn (PurchaseRequestItem $item): float => (float) $item->requested_qty), 4),
            ])
            ->sortBy('week_start')
            ->values();
    }

    /**
     * @param  Collection<int, array{week_start: string, quantity: float|int|string}>  $observations
     * @return array{status: string, observation_count: int, span_weeks: int, coverage_percentage: float, staleness_weeks: int|null, median_weekly_demand: float|null, slope_per_week: float|null, residual_mad: float|null, variability_band: float|null, trend: string, last_observed_week: string|null, reason: string, forecasts: array<int, array{week_start: string, quantity: float, lower: float, upper: float}>}
     */
    public function forecastSeries(Collection $observations, CarbonInterface $forecastStart): array
    {
        $series = $observations
            ->map(fn (array $observation): array => [
                'week_start' => Carbon::parse($observation['week_start'])->startOfWeek(),
                'quantity' => max(0, (float) $observation['quantity']),
            ])
            ->sortBy('week_start')
            ->values();

        $count = $series->count();
        $forecastWeek = $forecastStart->copy()->startOfWeek();
        $lastCompleteWeek = $forecastWeek->copy()->subWeek();

        if ($count === 0) {
            return $this->insufficientForecast('Belum ada histori demand mingguan yang dapat dipakai.');
        }

        $firstWeek = $series->first()['week_start'];
        $lastObservedWeek = $series->last()['week_start'];
        $spanWeeks = max(1, (int) $firstWeek->diffInWeeks($lastCompleteWeek) + 1);
        $coverage = round(min(100, 100 * $count / $spanWeeks), 1);
        $staleness = max(0, (int) $lastObservedWeek->diffInWeeks($lastCompleteWeek));
        $medianDemand = $this->median($series->pluck('quantity')->map(fn ($value): float => (float) $value));

        if ($count < self::MIN_OBSERVATIONS) {
            return $this->insufficientForecast(
                'Histori belum cukup: minimum '.self::MIN_OBSERVATIONS.' minggu observasi diperlukan.',
                $count,
                $spanWeeks,
                $coverage,
                $staleness,
                $medianDemand,
                $lastObservedWeek,
            );
        }

        if ($coverage < self::MIN_COVERAGE_PERCENTAGE) {
            return $this->insufficientForecast(
                'Coverage histori terlalu rendah ('.number_format($coverage, 1, ',', '.').'%). Minimum '.number_format(self::MIN_COVERAGE_PERCENTAGE, 0).'%.',
                $count,
                $spanWeeks,
                $coverage,
                $staleness,
                $medianDemand,
                $lastObservedWeek,
            );
        }

        if ($staleness > self::MAX_STALENESS_WEEKS) {
            return $this->insufficientForecast(
                'Histori demand terlalu stale: observasi terakhir '.$staleness.' minggu sebelum periode terkini.',
                $count,
                $spanWeeks,
                $coverage,
                $staleness,
                $medianDemand,
                $lastObservedWeek,
            );
        }

        $points = $series->map(fn (array $observation): array => [
            'x' => (float) $firstWeek->diffInWeeks($observation['week_start']),
            'y' => (float) $observation['quantity'],
        ])->values();

        $slope = $this->theilSenSlope($points);
        $intercepts = $points->map(fn (array $point): float => $point['y'] - ($slope * $point['x']));
        $intercept = $this->median($intercepts) ?? 0.0;
        $absoluteResiduals = $points->map(
            fn (array $point): float => abs($point['y'] - ($intercept + ($slope * $point['x']))),
        );
        $residualMad = $this->median($absoluteResiduals) ?? 0.0;
        $variabilityBand = round(1.4826 * $residualMad, 4);
        $trendThreshold = max(0.01, 0.02 * max(1, $medianDemand ?? 0));
        $trend = abs($slope) <= $trendThreshold
            ? 'stable'
            : ($slope > 0 ? 'up' : 'down');

        $forecasts = collect(range(0, self::HORIZON_WEEKS - 1))
            ->map(function (int $offset) use ($forecastWeek, $firstWeek, $intercept, $slope, $variabilityBand): array {
                $week = $forecastWeek->copy()->addWeeks($offset);
                $x = (float) $firstWeek->diffInWeeks($week);
                $prediction = max(0, $intercept + ($slope * $x));

                return [
                    'week_start' => $week->toDateString(),
                    'quantity' => round($prediction, 4),
                    'lower' => round(max(0, $prediction - $variabilityBand), 4),
                    'upper' => round($prediction + $variabilityBand, 4),
                ];
            })
            ->all();

        return [
            'status' => 'ready',
            'observation_count' => $count,
            'span_weeks' => $spanWeeks,
            'coverage_percentage' => $coverage,
            'staleness_weeks' => $staleness,
            'median_weekly_demand' => $medianDemand === null ? null : round($medianDemand, 4),
            'slope_per_week' => round($slope, 4),
            'residual_mad' => round($residualMad, 4),
            'variability_band' => $variabilityBand,
            'trend' => $trend,
            'last_observed_week' => $lastObservedWeek->toDateString(),
            'reason' => 'Forecast memakai robust Theil–Sen trend; variability band berasal dari residual MAD dan bukan confidence interval probabilistik.',
            'forecasts' => $forecasts,
        ];
    }

    public function forecastQuantity(PurchaseRequestItem $representative, int $weekOffset): ?float
    {
        $forecast = $this->forecast($representative);

        if ($forecast['status'] !== 'ready') {
            return null;
        }

        return $forecast['forecasts'][$weekOffset]['quantity'] ?? null;
    }

    public function forecastBand(PurchaseRequestItem $representative, int $weekOffset): ?string
    {
        $forecast = $this->forecast($representative);

        if ($forecast['status'] !== 'ready' || ! isset($forecast['forecasts'][$weekOffset])) {
            return null;
        }

        $week = $forecast['forecasts'][$weekOffset];

        return number_format($week['lower'], 2, ',', '.').' – '.number_format($week['upper'], 2, ',', '.');
    }

    /** @param Collection<int, array{x: float, y: float}> $points */
    private function theilSenSlope(Collection $points): float
    {
        $slopes = collect();
        $count = $points->count();

        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $dx = $points[$j]['x'] - $points[$i]['x'];

                if (abs($dx) <= 0.0001) {
                    continue;
                }

                $slopes->push(($points[$j]['y'] - $points[$i]['y']) / $dx);
            }
        }

        return $this->median($slopes) ?? 0.0;
    }

    /** @param Collection<int, float|int> $values */
    private function median(Collection $values): ?float
    {
        $sorted = $values
            ->map(fn ($value): float => (float) $value)
            ->sort()
            ->values();
        $count = $sorted->count();

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $sorted->get($middle);
        }

        return ((float) $sorted->get($middle - 1) + (float) $sorted->get($middle)) / 2;
    }

    /**
     * @return array{status: string, observation_count: int, span_weeks: int, coverage_percentage: float, staleness_weeks: int|null, median_weekly_demand: float|null, slope_per_week: float|null, residual_mad: float|null, variability_band: float|null, trend: string, last_observed_week: string|null, reason: string, forecasts: array<int, array{week_start: string, quantity: float, lower: float, upper: float}>}
     */
    private function insufficientForecast(
        string $reason,
        int $observations = 0,
        int $spanWeeks = 0,
        float $coverage = 0,
        ?int $staleness = null,
        ?float $medianDemand = null,
        ?CarbonInterface $lastObservedWeek = null,
    ): array {
        return [
            'status' => 'insufficient',
            'observation_count' => $observations,
            'span_weeks' => $spanWeeks,
            'coverage_percentage' => $coverage,
            'staleness_weeks' => $staleness,
            'median_weekly_demand' => $medianDemand === null ? null : round($medianDemand, 4),
            'slope_per_week' => null,
            'residual_mad' => null,
            'variability_band' => null,
            'trend' => 'unknown',
            'last_observed_week' => $lastObservedWeek?->toDateString(),
            'reason' => $reason,
            'forecasts' => [],
        ];
    }

    /** @return array<string> */
    private function eligibleStatuses(): array
    {
        return [
            PurchaseRequestStatus::Approved->value,
            PurchaseRequestStatus::PartiallyAllocated->value,
            PurchaseRequestStatus::FullyAllocated->value,
            PurchaseRequestStatus::PoGenerated->value,
            PurchaseRequestStatus::Closed->value,
        ];
    }
}
