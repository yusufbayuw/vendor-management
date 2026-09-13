<?php

namespace App\Services\Analytics;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\Support\RobustOutlierDetector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProcessBottleneckAnalyticsService
{
    public const BASELINE_DAYS = 365;

    public const MIN_BASELINE_OBSERVATIONS = 5;

    public const ROBUST_Z_THRESHOLD = 3.5;

    /** @var array<int, Collection<string, array<string, mixed>>> */
    private array $analysisCache = [];

    /** @var array<string, Collection<int, PurchaseOrder>> */
    private array $historyCache = [];

    public function __construct(
        private readonly UserAccessService $access,
        private readonly ProcessPerformanceAnalyticsService $performance,
    ) {}

    public function query(User $user): Builder
    {
        return $this->performance->query($user)
            ->whereNotIn('status', [
                PurchaseOrderStatus::Draft->value,
                PurchaseOrderStatus::Cancelled->value,
            ]);
    }

    /**
     * @return Collection<string, array{stage: string, label: string, current_hours: float|null, status: string, is_anomaly: bool, baseline_count: int, median: float|null, mad: float|null, deviation_percentage: float|null, robust_z: float|null, reason: string}>
     */
    public function stageAnalyses(PurchaseOrder $order): Collection
    {
        if (isset($this->analysisCache[$order->getKey()])) {
            return $this->analysisCache[$order->getKey()];
        }

        $history = $this->historicalOrders($order);

        return $this->analysisCache[$order->getKey()] = collect($this->stageDefinitions())
            ->mapWithKeys(function (array $definition, string $stage) use ($order, $history): array {
                $current = $this->stageHours($order, $stage);
                $baseline = $history
                    ->map(fn (PurchaseOrder $historicalOrder): ?float => $this->stageHours($historicalOrder, $stage))
                    ->filter(fn (?float $hours): bool => $hours !== null && $hours >= 0)
                    ->values();

                return [
                    $stage => $this->analyzeStageValues(
                        $stage,
                        $definition['label'],
                        $current,
                        $baseline,
                    ),
                ];
            });
    }

    /**
     * @param  Collection<int, float|int|string>  $baseline
     * @return array{stage: string, label: string, current_hours: float|null, status: string, is_anomaly: bool, baseline_count: int, median: float|null, mad: float|null, deviation_percentage: float|null, robust_z: float|null, reason: string}
     */
    public function analyzeStageValues(
        string $stage,
        string $label,
        ?float $currentHours,
        Collection $baseline,
    ): array {
        if ($currentHours === null) {
            return $this->emptyAnalysis(
                $stage,
                $label,
                null,
                'incomplete',
                'Tahap belum selesai sehingga durasi belum dapat dinilai.',
            );
        }

        if ($currentHours < 0) {
            return $this->emptyAnalysis(
                $stage,
                $label,
                $currentHours,
                'invalid',
                'Durasi negatif menunjukkan timestamp yang perlu diperiksa.',
            );
        }

        $result = RobustOutlierDetector::analyze(
            $currentHours,
            $baseline,
            self::MIN_BASELINE_OBSERVATIONS,
            self::ROBUST_Z_THRESHOLD,
        );

        $status = match ($result['status']) {
            'high' => 'slow',
            'low' => 'fast',
            default => $result['status'],
        };

        return [
            'stage' => $stage,
            'label' => $label,
            'current_hours' => $currentHours,
            'status' => $status,
            'is_anomaly' => $result['is_anomaly'],
            'baseline_count' => $result['baseline_count'],
            'median' => $result['median'],
            'mad' => $result['mad'],
            'deviation_percentage' => $result['deviation_percentage'],
            'robust_z' => $result['robust_z'],
            'reason' => match ($result['reason_code']) {
                'insufficient_baseline' => 'Baseline belum cukup untuk menilai bottleneck tahap ini.',
                'zero_dispersion_equal' => 'Durasi sama dengan baseline historis yang stabil.',
                'zero_dispersion_changed' => $status === 'slow'
                    ? 'Durasi lebih lambat dari baseline historis yang seluruh nilainya identik.'
                    : 'Durasi lebih cepat dari baseline historis yang seluruh nilainya identik.',
                'threshold_exceeded' => $status === 'slow'
                    ? 'Durasi jauh lebih lambat dari pola historis tahap yang sama.'
                    : 'Durasi jauh lebih cepat dari pola historis tahap yang sama.',
                default => 'Durasi masih berada dalam variasi historis yang wajar.',
            },
        ];
    }

    public function overallStatus(PurchaseOrder $order): string
    {
        $analyses = $this->stageAnalyses($order);

        if ($analyses->contains('status', 'invalid')) {
            return 'data_quality';
        }

        if ($analyses->contains('status', 'slow')) {
            return 'bottleneck';
        }

        if ($analyses->contains('status', 'fast')) {
            return 'unusually_fast';
        }

        if ($analyses->contains('status', 'normal')) {
            return 'normal';
        }

        return 'insufficient';
    }

    public function bottleneckStage(PurchaseOrder $order): string
    {
        $slowest = $this->stageAnalyses($order)
            ->filter(fn (array $analysis): bool => $analysis['status'] === 'slow')
            ->sortByDesc(fn (array $analysis): float => (float) ($analysis['robust_z'] ?? INF))
            ->first();

        if ($slowest !== null) {
            return $slowest['label'];
        }

        $invalid = $this->stageAnalyses($order)->firstWhere('status', 'invalid');

        return $invalid['label'] ?? '-';
    }

    public function slowStageCount(PurchaseOrder $order): int
    {
        return $this->stageAnalyses($order)->where('status', 'slow')->count();
    }

    public function evaluatedStageCount(PurchaseOrder $order): int
    {
        return $this->stageAnalyses($order)
            ->whereIn('status', ['normal', 'slow', 'fast'])
            ->count();
    }

    public function maximumRobustZ(PurchaseOrder $order): ?float
    {
        $values = $this->stageAnalyses($order)
            ->pluck('robust_z')
            ->filter(fn ($value): bool => $value !== null);

        return $values->isEmpty() ? null : (float) $values->max();
    }

    public function diagnosticSummary(PurchaseOrder $order): string
    {
        $analyses = $this->stageAnalyses($order);
        $important = $analyses
            ->filter(fn (array $analysis): bool => in_array($analysis['status'], ['slow', 'fast', 'invalid'], true))
            ->map(function (array $analysis): string {
                $current = $this->performance->formatHours($analysis['current_hours']);
                $median = $this->performance->formatHours($analysis['median']);
                $z = $analysis['robust_z'] === null
                    ? '-'
                    : number_format((float) $analysis['robust_z'], 2, ',', '.');

                return $analysis['label'].': '.$current.' vs median '.$median.' (z '.$z.')';
            });

        if ($important->isNotEmpty()) {
            return $important->implode(' | ');
        }

        if ($analyses->contains('status', 'normal')) {
            return 'Tidak ada cycle-time anomaly pada stage yang memiliki baseline cukup.';
        }

        return 'Baseline historis belum cukup untuk menilai bottleneck secara statistik.';
    }

    public function stageStatus(PurchaseOrder $order, string $stage): string
    {
        return (string) ($this->stageAnalyses($order)->get($stage)['status'] ?? 'incomplete');
    }

    public function stageRobustZ(PurchaseOrder $order, string $stage): ?float
    {
        return $this->stageAnalyses($order)->get($stage)['robust_z'] ?? null;
    }

    public function stageMedian(PurchaseOrder $order, string $stage): ?float
    {
        return $this->stageAnalyses($order)->get($stage)['median'] ?? null;
    }

    public function stageBaselineCount(PurchaseOrder $order, string $stage): int
    {
        return (int) ($this->stageAnalyses($order)->get($stage)['baseline_count'] ?? 0);
    }

    /** @return array<string, array{label: string}> */
    public function stageDefinitions(): array
    {
        return [
            'pr_approval' => ['label' => 'PR Approval'],
            'po_generation' => ['label' => 'PR → PO'],
            'po_approval' => ['label' => 'PO Approval'],
            'issue' => ['label' => 'Approval → Issue'],
            'acknowledgement' => ['label' => 'Supplier Ack'],
            'first_receipt' => ['label' => 'Ack → First Receipt'],
            'average_qc' => ['label' => 'Average QC'],
            'delivery_qc_cycle' => ['label' => 'Delivery/QC Cycle'],
            'invoice_approval' => ['label' => 'Invoice Approval'],
            'payment_cycle' => ['label' => 'Payment Cycle'],
        ];
    }

    public function stageHours(PurchaseOrder $order, string $stage): ?float
    {
        return match ($stage) {
            'pr_approval' => $this->performance->prApprovalHours($order),
            'po_generation' => $this->performance->poGenerationHours($order),
            'po_approval' => $this->performance->poApprovalHours($order),
            'issue' => $this->performance->issueHours($order),
            'acknowledgement' => $this->performance->acknowledgementHours($order),
            'first_receipt' => $this->performance->firstReceiptHours($order),
            'average_qc' => $this->performance->averageQcHours($order),
            'delivery_qc_cycle' => $this->performance->deliveryQcCycleHours($order),
            'invoice_approval' => $this->performance->invoiceApprovalHours($order),
            'payment_cycle' => $this->performance->paymentCycleHours($order),
            default => null,
        };
    }

    /** @return Collection<int, PurchaseOrder> */
    private function historicalOrders(PurchaseOrder $order): Collection
    {
        if ($order->order_date === null) {
            return collect();
        }

        $to = $order->order_date->copy();
        $from = $to->copy()->subDays(self::BASELINE_DAYS);
        $cacheKey = implode(':', [
            $order->sppg_kitchen_id,
            $from->toDateString(),
            $to->toDateString(),
        ]);

        if (isset($this->historyCache[$cacheKey])) {
            return $this->historyCache[$cacheKey];
        }

        return $this->historyCache[$cacheKey] = PurchaseOrder::query()
            ->with([
                'purchaseRequest',
                'goodsReceipts',
                'invoice.payments',
            ])
            ->where('sppg_kitchen_id', $order->sppg_kitchen_id)
            ->whereKeyNot($order->getKey())
            ->whereDate('order_date', '>=', $from->toDateString())
            ->whereDate('order_date', '<', $to->toDateString())
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->get();
    }

    /**
     * @return array{stage: string, label: string, current_hours: float|null, status: string, is_anomaly: bool, baseline_count: int, median: float|null, mad: float|null, deviation_percentage: float|null, robust_z: float|null, reason: string}
     */
    private function emptyAnalysis(
        string $stage,
        string $label,
        ?float $currentHours,
        string $status,
        string $reason,
    ): array {
        return [
            'stage' => $stage,
            'label' => $label,
            'current_hours' => $currentHours,
            'status' => $status,
            'is_anomaly' => false,
            'baseline_count' => 0,
            'median' => null,
            'mad' => null,
            'deviation_percentage' => null,
            'robust_z' => null,
            'reason' => $reason,
        ];
    }
}
