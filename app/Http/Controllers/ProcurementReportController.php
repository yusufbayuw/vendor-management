<?php

namespace App\Http\Controllers;

use App\Enums\BusinessFlowStage;
use App\Enums\SystemPermission;
use App\Services\Reporting\ProcurementReportService;
use App\Services\Workflow\ProcureToPayLifecycleService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcurementReportController extends Controller
{
    public function csv(Request $request, ProcurementReportService $reports, ProcureToPayLifecycleService $lifecycle): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can(SystemPermission::ReportsView->value), 403);

        $orders = $reports->purchaseOrders(
            $user,
            $request->query('from'),
            $request->query('to'),
        );

        $filename = 'procurement-'.$request->query('from', today()->subDays(29)->toDateString()).'-'.$request->query('to', today()->toDateString()).'.csv';

        return response()->streamDownload(function () use ($orders, $lifecycle): void {
            $stream = fopen('php://output', 'wb');

            $headers = [
                'Nomor PO',
                'Tanggal',
                'SPPG',
                'Supplier',
                'Status',
                'Subtotal',
                'Pajak',
                'Diskon',
                'Total',
                'Progress Flow (%)',
                'Current Stage',
            ];

            foreach (BusinessFlowStage::cases() as $stage) {
                $headers[] = $stage->navigationLabel().' Status';
                $headers[] = $stage->navigationLabel().' Mulai';
                $headers[] = $stage->navigationLabel().' Selesai';
                $headers[] = $stage->navigationLabel().' Durasi Jam';
            }

            fputcsv($stream, $headers);

            foreach ($orders as $order) {
                $snapshot = $lifecycle->snapshot($order);
                $current = $lifecycle->currentStage($order);
                $row = [
                    $order->number,
                    $order->order_date?->format('d/m/Y'),
                    $order->kitchen?->name,
                    $order->supplier?->display_name ?: $order->supplier?->legal_name,
                    $order->status->value,
                    $order->subtotal,
                    $order->tax_amount,
                    $order->discount_amount,
                    $order->total_amount,
                    $lifecycle->progress($order),
                    $current->navigationLabel(),
                ];

                foreach (BusinessFlowStage::cases() as $stage) {
                    $flow = $snapshot[$stage->value];
                    $row[] = $flow['status'];
                    $row[] = $flow['started_at']?->format('d/m/Y H:i');
                    $row[] = $flow['completed_at']?->format('d/m/Y H:i');
                    $row[] = $flow['duration_hours'];
                }

                fputcsv($stream, $row);
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
