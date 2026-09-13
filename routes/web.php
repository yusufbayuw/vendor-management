<?php

use App\Http\Controllers\PrivateVendorFileController;
use App\Http\Controllers\ProcurementReportController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaIconController;
use App\Http\Controllers\TransactionDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/pwa/icon/{size}.png', PwaIconController::class)
    ->whereNumber('size')
    ->name('pwa.icon');

Route::middleware('auth')->group(function (): void {
    Route::get('/reports/procurement.csv', [ProcurementReportController::class, 'csv'])
        ->name('reports.procurement.csv');

    Route::prefix('push')->name('push.')->group(function (): void {
        Route::get('/vapid-public-key', [PushSubscriptionController::class, 'publicKey'])
            ->name('vapid-public-key');
        Route::post('/subscriptions', [PushSubscriptionController::class, 'store'])
            ->name('subscriptions.store');
        Route::delete('/subscriptions', [PushSubscriptionController::class, 'destroy'])
            ->name('subscriptions.destroy');
    });

    Route::prefix('documents')->name('documents.')->group(function (): void {
        Route::get('/purchase-orders/{purchaseOrder}', [TransactionDocumentController::class, 'purchaseOrder'])
            ->name('purchase-orders.show');
        Route::get('/goods-receipts/{goodsReceipt}', [TransactionDocumentController::class, 'goodsReceipt'])
            ->name('goods-receipts.show');
        Route::get('/invoices/{invoice}', [TransactionDocumentController::class, 'invoice'])
            ->name('invoices.show');
    });

    Route::prefix('files')->name('files.')->group(function (): void {
        Route::get('/supplier-documents/{supplierDocument}', [PrivateVendorFileController::class, 'supplierDocument'])
            ->name('supplier-documents.show');
        Route::get('/goods-receipt-attachments/{goodsReceiptAttachment}', [PrivateVendorFileController::class, 'goodsReceiptAttachment'])
            ->name('goods-receipt-attachments.show');
        Route::get('/payment-attachments/{paymentAttachment}', [PrivateVendorFileController::class, 'paymentAttachment'])
            ->name('payment-attachments.show');
        Route::get('/delivery-notes/{deliverySchedule}', [PrivateVendorFileController::class, 'deliveryNote'])
            ->name('delivery-notes.show');
        Route::get('/invoice-files/{invoice}', [PrivateVendorFileController::class, 'invoice'])
            ->name('invoice-files.show');
    });
});
