<?php

namespace App\Http\Controllers;

use App\Enums\SystemPermission;
use App\Services\Reporting\ProcurementReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcurementReportController extends Controller
{
    public function csv(Request $request, ProcurementReportService $reports): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can(SystemPermission::ReportsView->value), 403);

        $orders = $reports->purchaseOrders(
            $user,
            $request->query('from'),
            $request->query('to'),
        );

        $filename = 'procurement-'.$request->query('from', today()->subDays(29)->toDateString()).'-'.$request->query('to', today()->toDateString()).'.csv';

        return response()->streamDownload(function () use ($orders): void {
            $stream = fopen('php://output', 'wb');

            fputcsv($stream, [
                'Nomor PO',
                'Tanggal',
                'SPPG',
                'Supplier',
                'Status',
                'Subtotal',
                'Pajak',
                'Diskon',
                'Total',
            ]);

            foreach ($orders as $order) {
                fputcsv($stream, [
                    $order->number,
                    $order->order_date?->format('d/m/Y'),
                    $order->kitchen?->name,
                    $order->supplier?->display_name ?: $order->supplier?->legal_name,
                    $order->status->value,
                    $order->subtotal,
                    $order->tax_amount,
                    $order->discount_amount,
                    $order->total_amount,
                ]);
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
