<?php

namespace App\Services\Analytics\Support;

use Illuminate\Support\Collection;

class RobustOutlierDetector
{
    /**
     * @param  Collection<int, float|int|string>  $baseline
     * @return array{status: string, is_anomaly: bool, baseline_count: int, median: float|null, mad: float|null, deviation_percentage: float|null, robust_z: float|null, reason_code: string}
     */
    public static function analyze(
        float $currentValue,
        Collection $baseline,
        int $minimumObservations,
        float $threshold,
    ): array {
        $values = $baseline
            ->map(fn ($value): float => (float) $value)
            ->filter(fn (float $value): bool => $value >= 0)
            ->sort()
            ->values();

        $count = $values->count();
        $median = self::median($values);

        if ($count < $minimumObservations || $median === null) {
            return [
                'status' => 'insufficient',
                'is_anomaly' => false,
                'baseline_count' => $count,
                'median' => $median,
                'mad' => null,
                'deviation_percentage' => $median !== null && abs($median) > 0.0001
                    ? round(100 * ($currentValue - $median) / $median, 2)
                    : null,
                'robust_z' => null,
                'reason_code' => 'insufficient_baseline',
            ];
        }

        $absoluteDeviations = $values
            ->map(fn (float $value): float => abs($value - $median))
            ->sort()
            ->values();
        $mad = self::median($absoluteDeviations) ?? 0.0;
        $deviationPercentage = abs($median) > 0.0001
            ? round(100 * ($currentValue - $median) / $median, 2)
            : null;

        if ($mad <= 0.0001) {
            $isDifferent = abs($currentValue - $median) > 0.01;

            if (! $isDifferent) {
                return [
                    'status' => 'normal',
                    'is_anomaly' => false,
                    'baseline_count' => $count,
                    'median' => round($median, 2),
                    'mad' => 0.0,
                    'deviation_percentage' => $deviationPercentage,
                    'robust_z' => null,
                    'reason_code' => 'zero_dispersion_equal',
                ];
            }

            return [
                'status' => $currentValue > $median ? 'high' : 'low',
                'is_anomaly' => true,
                'baseline_count' => $count,
                'median' => round($median, 2),
                'mad' => 0.0,
                'deviation_percentage' => $deviationPercentage,
                'robust_z' => null,
                'reason_code' => 'zero_dispersion_changed',
            ];
        }

        $robustZ = 0.6745 * ($currentValue - $median) / $mad;
        $isAnomaly = abs($robustZ) >= $threshold;

        return [
            'status' => $isAnomaly ? ($robustZ > 0 ? 'high' : 'low') : 'normal',
            'is_anomaly' => $isAnomaly,
            'baseline_count' => $count,
            'median' => round($median, 2),
            'mad' => round($mad, 2),
            'deviation_percentage' => $deviationPercentage,
            'robust_z' => round($robustZ, 2),
            'reason_code' => $isAnomaly ? 'threshold_exceeded' : 'within_range',
        ];
    }

    /** @param Collection<int, float> $values */
    private static function median(Collection $values): ?float
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
}
