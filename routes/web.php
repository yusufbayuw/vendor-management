<?php

use App\Http\Controllers\ProcurementReportController;
use App\Http\Controllers\TransactionDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/reports/procurement.csv', [ProcurementReportController::class, 'csv'])
        ->name('reports.procurement.csv');

    Route::prefix('documents')->name('documents.')->group(function (): void {
        Route::get('/purchase-orders/{purchaseOrder}', [TransactionDocumentController::class, 'purchaseOrder'])
            ->name('purchase-orders.show');
        Route::get('/goods-receipts/{goodsReceipt}', [TransactionDocumentController::class, 'goodsReceipt'])
            ->name('goods-receipts.show');
        Route::get('/invoices/{invoice}', [TransactionDocumentController::class, 'invoice'])
            ->name('invoices.show');
    });
});
