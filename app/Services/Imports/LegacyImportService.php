<?php

namespace App\Services\Imports;

use App\Actions\Supplier\ActivateSupplierWithOverrideAction;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LegacyImportType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseAllocationStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierManagementMode;
use App\Enums\SupplierStatus;
use App\Models\DataProvenance;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleItem;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Invoice;
use App\Models\LegacyImportBatch;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use App\Support\ImportExecutionContext;
use BackedEnum;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class LegacyImportService
{
    public function __construct(
        private readonly LegacyTabularReader $reader,
        private readonly VendorFileStorage $files,
        private readonly ActivateSupplierWithOverrideAction $activateSupplier,
    ) {}

    public function execute(LegacyImportBatch $batch, User $actor): LegacyImportBatch
    {
        if (! in_array($batch->status, ['pending', 'failed', 'completed_with_errors'], true)) {
            throw new DomainException('Batch import ini sedang atau sudah selesai diproses.');
        }

        $batch->forceFill([
            'status' => 'processing',
            'imported_rows' => 0,
            'failed_rows' => 0,
            'summary' => null,
            'imported_at' => null,
        ])->save();

        try {
            $rows = $this->reader->rows(
                $this->files->absolutePath($batch->file_path),
                $batch->original_filename,
            );
            $this->assertColumns($batch->import_type, $rows);

            $result = ImportExecutionContext::withoutWorkflowNotifications(function () use ($batch, $actor, $rows): array {
                $imported = 0;
                $failed = 0;
                $errors = [];

                foreach ($rows as $row) {
                    try {
                        DB::transaction(function () use ($batch, $actor, $row): void {
                            $sourceable = $this->importRow($batch, $actor, $row);
                            $this->recordProvenance($batch, $sourceable, $row);
                        }, 3);

                        $imported++;
                    } catch (Throwable $exception) {
                        $failed++;

                        if (count($errors) < 100) {
                            $errors[] = [
                                'row' => (int) ($row['_source_row'] ?? 0),
                                'message' => $exception->getMessage(),
                            ];
                        }
                    }
                }

                return [
                    'imported' => $imported,
                    'failed' => $failed,
                    'errors' => $errors,
                ];
            });

            $imported = $result['imported'];
            $failed = $result['failed'];
            $errors = $result['errors'];
            $syntheticRecords = DataProvenance::query()
                ->where('legacy_import_batch_id', $batch->getKey())
                ->where('provenance_type', 'legacy_import_synthetic')
                ->count();

            $batch->forceFill([
                'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
                'total_rows' => count($rows),
                'imported_rows' => $imported,
                'failed_rows' => $failed,
                'summary' => [
                    'workflow_replayed' => false,
                    'synthetic_upstream_records' => $syntheticRecords,
                    'errors' => $errors,
                ],
                'imported_at' => now(),
            ])->save();

            return $batch->refresh();
        } catch (Throwable $exception) {
            $batch->forceFill([
                'status' => 'failed',
                'summary' => [
                    'workflow_replayed' => false,
                    'fatal_error' => $exception->getMessage(),
                ],
                'imported_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    private function importRow(LegacyImportBatch $batch, User $actor, array $row): Model
    {
        return match ($batch->import_type) {
            LegacyImportType::Suppliers => $this->importSupplier($row, $actor),
            LegacyImportType::PurchaseRequests => $this->importPurchaseRequest($batch, $row, $actor),
            LegacyImportType::PurchaseOrders => $this->importPurchaseOrder($batch, $row, $actor),
            LegacyImportType::GoodsReceipts => $this->importGoodsReceipt($batch, $row, $actor),
            LegacyImportType::Invoices => $this->importInvoice($batch, $row, $actor),
            LegacyImportType::Payments => $this->importPayment($batch, $row, $actor),
        };
    }

    private function importSupplier(array $row, User $actor): Supplier
    {
        $supplier = Supplier::query()->updateOrCreate(
            ['code' => $this->required($row, 'code')],
            [
                'legal_name' => $this->required($row, 'legal_name'),
                'display_name' => $this->value($row, 'display_name'),
                'supplier_type' => $this->value($row, 'supplier_type') ?: 'company',
                'management_mode' => SupplierManagementMode::AdminManaged,
                'npwp' => $this->value($row, 'npwp'),
                'nib' => $this->value($row, 'nib'),
                'email' => $this->value($row, 'email'),
                'phone' => $this->value($row, 'phone'),
                'address' => $this->value($row, 'address'),
                'notes' => $this->value($row, 'notes'),
            ],
        );

        $targetStatus = $this->enumValue(SupplierStatus::class, $this->value($row, 'status') ?: 'active');

        if ($targetStatus === SupplierStatus::Active && $supplier->status !== SupplierStatus::Active) {
            return $this->activateSupplier->execute(
                $supplier,
                $actor,
                'Migrasi supplier existing dari data legacy. Dokumen dan akun portal dapat dilengkapi setelah operasional berjalan.',
                true,
                true,
            );
        }

        if ($targetStatus !== SupplierStatus::Active && $supplier->status !== $targetStatus) {
            $supplier->forceFill(['status' => $targetStatus])->save();
        }

        return $supplier->refresh();
    }

    private function importPurchaseRequest(LegacyImportBatch $batch, array $row, User $actor): PurchaseRequest
    {
        $kitchen = $this->kitchen($batch, $row);
        $status = $this->enumValue(PurchaseRequestStatus::class, $this->required($row, 'status'));

        $request = PurchaseRequest::query()->updateOrCreate(
            ['number' => $this->required($row, 'number')],
            [
                'sppg_kitchen_id' => $kitchen->getKey(),
                'requested_by' => $actor->getKey(),
                'period_start' => $this->date($row, 'period_start'),
                'period_end' => $this->date($row, 'period_end'),
                'needed_from' => $this->date($row, 'needed_from'),
                'needed_until' => $this->date($row, 'needed_until'),
                'description' => $this->value($row, 'description'),
                'notes' => $this->value($row, 'notes'),
                'status' => $status,
                'submitted_at' => $this->dateTime($row, 'submitted_at'),
                'approved_at' => $this->dateTime($row, 'approved_at'),
            ],
        );

        if (filled($this->value($row, 'product_code'))) {
            $product = Product::query()->where('code', $this->required($row, 'product_code'))->firstOrFail();
            $unit = $this->unit($row, $product->default_unit_id);

            PurchaseRequestItem::query()->updateOrCreate(
                [
                    'purchase_request_id' => $request->getKey(),
                    'product_id' => $product->getKey(),
                    'unit_id' => $unit->getKey(),
                    'description' => $this->value($row, 'item_description'),
                ],
                [
                    'requested_qty' => $this->number($row, 'requested_qty', 0),
                    'estimated_unit_price' => $this->nullableNumber($row, 'estimated_unit_price'),
                    'estimated_total' => $this->nullableNumber($row, 'estimated_total'),
                    'quality_specification' => $this->value($row, 'quality_specification'),
                    'notes' => $this->value($row, 'item_notes'),
                ],
            );
        }

        return $request->refresh();
    }

    private function importPurchaseOrder(LegacyImportBatch $batch, array $row, User $actor): PurchaseOrder
    {
        $supplier = Supplier::query()->where('code', $this->required($row, 'supplier_code'))->firstOrFail();
        $kitchen = $this->kitchen($batch, $row);
        $request = $this->resolvePurchaseRequestForPurchaseOrder(
            $batch,
            $row,
            $actor,
            $kitchen,
            $this->required($row, 'number'),
        );
        $status = $this->enumValue(PurchaseOrderStatus::class, $this->required($row, 'status'));

        $purchaseOrder = PurchaseOrder::query()->updateOrCreate(
            ['number' => $this->required($row, 'number')],
            [
                'supplier_id' => $supplier->getKey(),
                'sppg_kitchen_id' => $kitchen->getKey(),
                'purchase_request_id' => $request->getKey(),
                'order_date' => $this->requiredDate($row, 'order_date'),
                'delivery_start' => $this->date($row, 'delivery_start'),
                'delivery_end' => $this->date($row, 'delivery_end'),
                'subtotal' => $this->number($row, 'subtotal', 0),
                'tax_amount' => $this->number($row, 'tax_amount', 0),
                'discount_amount' => $this->number($row, 'discount_amount', 0),
                'total_amount' => $this->number($row, 'total_amount', 0),
                'status' => $status,
                'approved_at' => $this->dateTime($row, 'approved_at'),
                'issued_at' => $this->dateTime($row, 'issued_at'),
                'acknowledged_at' => $this->dateTime($row, 'acknowledged_at'),
                'notes' => $this->value($row, 'notes'),
                'created_by' => $actor->getKey(),
            ],
        );

        if (filled($this->value($row, 'product_code'))) {
            $product = Product::query()->where('code', $this->required($row, 'product_code'))->firstOrFail();
            $unit = $this->unit($row, $product->default_unit_id);
            $quantity = $this->number($row, 'ordered_qty', 0);
            $unitPrice = $this->number($row, 'unit_price', 0);
            $requestItem = $this->resolvePurchaseRequestItem(
                $batch,
                $row,
                $request,
                $product,
                $unit,
                max(0.0001, $quantity),
                $unitPrice,
            );

            $allocation = PurchaseAllocation::query()->firstOrCreate(
                [
                    'purchase_request_item_id' => $requestItem->getKey(),
                    'supplier_id' => $supplier->getKey(),
                    'allocated_qty' => $quantity,
                ],
                [
                    'unit_price' => $unitPrice,
                    'subtotal' => round($quantity * $unitPrice, 2),
                    'status' => PurchaseAllocationStatus::PoGenerated,
                    'allocated_by' => $actor->getKey(),
                    'allocated_at' => now(),
                ],
            );

            PurchaseOrderItem::query()->updateOrCreate(
                ['purchase_allocation_id' => $allocation->getKey()],
                [
                    'purchase_order_id' => $purchaseOrder->getKey(),
                    'purchase_request_item_id' => $requestItem->getKey(),
                    'product_id' => $product->getKey(),
                    'unit_id' => $unit->getKey(),
                    'product_name_snapshot' => $product->name,
                    'description_snapshot' => $this->value($row, 'item_description'),
                    'unit_name_snapshot' => $unit->symbol ?: $unit->name,
                    'ordered_qty' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $this->nullableNumber($row, 'item_subtotal') ?? round($quantity * $unitPrice, 2),
                    'delivered_qty' => $this->number($row, 'delivered_qty', 0),
                    'accepted_qty' => $this->number($row, 'accepted_qty', 0),
                    'rejected_qty' => $this->number($row, 'rejected_qty', 0),
                ],
            );
        }

        return $purchaseOrder->refresh();
    }

    private function importGoodsReceipt(LegacyImportBatch $batch, array $row, User $actor): GoodsReceipt
    {
        $receivedAt = $this->requiredDateTime($row, 'received_at');
        $status = $this->enumValue(GoodsReceiptStatus::class, $this->required($row, 'status'));
        $receiptNumber = $this->required($row, 'number');
        $purchaseOrder = $this->resolvePurchaseOrderForDownstream(
            $batch,
            $row,
            $actor,
            $status === GoodsReceiptStatus::Completed
                ? PurchaseOrderStatus::Fulfilled
                : PurchaseOrderStatus::PartiallyDelivered,
            $receivedAt->toDateString(),
            $this->number($row, 'po_amount', 0),
            'Penerimaan barang diimport tanpa PO upstream yang sudah tersedia.',
        );
        $scheduleNumber = $this->value($row, 'delivery_schedule_number') ?: 'LEGACY-'.$receiptNumber;

        $schedule = DeliverySchedule::query()->updateOrCreate(
            ['number' => $scheduleNumber],
            [
                'purchase_order_id' => $purchaseOrder->getKey(),
                'planned_delivery_at' => $receivedAt,
                'status' => DeliveryScheduleStatus::Received,
                'created_by' => $actor->getKey(),
                'confirmed_at' => $receivedAt,
            ],
        );

        $receipt = GoodsReceipt::query()->updateOrCreate(
            ['number' => $receiptNumber],
            [
                'purchase_order_id' => $purchaseOrder->getKey(),
                'delivery_schedule_id' => $schedule->getKey(),
                'supplier_id' => $purchaseOrder->supplier_id,
                'sppg_kitchen_id' => $purchaseOrder->sppg_kitchen_id,
                'received_at' => $receivedAt,
                'received_by' => $actor->getKey(),
                'supplier_representative' => $this->value($row, 'supplier_representative'),
                'delivery_note_number' => $this->value($row, 'delivery_note_number'),
                'status' => $status,
                'notes' => $this->value($row, 'notes'),
                'inspected_at' => $this->dateTime($row, 'inspected_at'),
            ],
        );

        if (filled($this->value($row, 'product_code'))) {
            $product = Product::query()->where('code', $this->required($row, 'product_code'))->firstOrFail();
            $unit = $this->unit($row, $product->default_unit_id);
            $poItem = $this->resolvePurchaseOrderItemForDownstream(
                $batch,
                $row,
                $actor,
                $purchaseOrder,
                $product,
                $unit,
            );

            $planned = $this->number($row, 'planned_qty', (float) $poItem->ordered_qty);
            $received = $this->number($row, 'received_qty', $planned);
            $accepted = $this->number($row, 'accepted_qty', $status === GoodsReceiptStatus::Completed ? $received : 0);
            $rejected = $this->number($row, 'rejected_qty', 0);

            $scheduleItem = DeliveryScheduleItem::query()->updateOrCreate(
                [
                    'delivery_schedule_id' => $schedule->getKey(),
                    'purchase_order_item_id' => $poItem->getKey(),
                ],
                [
                    'planned_qty' => $planned,
                    'unit_id' => $unit->getKey(),
                ],
            );

            GoodsReceiptItem::query()->updateOrCreate(
                [
                    'goods_receipt_id' => $receipt->getKey(),
                    'purchase_order_item_id' => $poItem->getKey(),
                ],
                [
                    'delivery_schedule_item_id' => $scheduleItem->getKey(),
                    'planned_qty' => $planned,
                    'received_qty' => $received,
                    'accepted_qty' => $accepted,
                    'rejected_qty' => $rejected,
                    'variance_qty' => $received - $planned,
                    'condition' => $this->value($row, 'condition'),
                    'rejection_reason' => $this->value($row, 'rejection_reason'),
                    'notes' => $this->value($row, 'item_notes'),
                ],
            );

            $poItem->forceFill([
                'delivered_qty' => max((float) $poItem->delivered_qty, $received),
                'accepted_qty' => max((float) $poItem->accepted_qty, $accepted),
                'rejected_qty' => max((float) $poItem->rejected_qty, $rejected),
            ])->save();
        }

        return $receipt->refresh();
    }

    private function importInvoice(LegacyImportBatch $batch, array $row, User $actor): Invoice
    {
        $status = $this->enumValue(InvoiceStatus::class, $this->required($row, 'status'));
        $payable = $this->number($row, 'payable_amount', 0);
        $invoiceDate = $this->requiredDate($row, 'invoice_date');
        $purchaseOrder = $this->resolvePurchaseOrderForDownstream(
            $batch,
            $row,
            $actor,
            PurchaseOrderStatus::Invoiced,
            $this->date($row, 'order_date') ?? $invoiceDate,
            $this->number($row, 'po_amount', $payable),
            'Invoice diimport tanpa PO upstream yang sudah tersedia.',
        );

        return Invoice::query()->updateOrCreate(
            ['number' => $this->required($row, 'number')],
            [
                'supplier_invoice_number' => $this->value($row, 'supplier_invoice_number'),
                'purchase_order_id' => $purchaseOrder->getKey(),
                'supplier_id' => $purchaseOrder->supplier_id,
                'sppg_kitchen_id' => $purchaseOrder->sppg_kitchen_id,
                'invoice_date' => $invoiceDate,
                'due_date' => $this->date($row, 'due_date'),
                'po_amount' => $this->number($row, 'po_amount', (float) $purchaseOrder->total_amount),
                'adjustment_amount' => $this->number($row, 'adjustment_amount', 0),
                'withholding_tax_amount' => $this->number($row, 'withholding_tax_amount', 0),
                'total_amount' => $this->number($row, 'total_amount', $payable),
                'payable_amount' => $payable,
                'status' => $status,
                'issued_at' => $this->dateTime($row, 'issued_at'),
                'approved_at' => $this->dateTime($row, 'approved_at'),
                'notes' => $this->value($row, 'notes'),
                'created_by' => $actor->getKey(),
            ],
        );
    }

    private function importPayment(LegacyImportBatch $batch, array $row, User $actor): Payment
    {
        $status = $this->enumValue(PaymentStatus::class, $this->required($row, 'status'));
        $method = $this->enumValue(PaymentMethod::class, $this->required($row, 'payment_method'));
        $paymentDate = $this->requiredDate($row, 'payment_date');
        $amount = $this->number($row, 'amount', 0);
        $invoice = $this->resolveInvoiceForPayment($batch, $row, $actor, $paymentDate, $amount);

        $payment = Payment::query()->updateOrCreate(
            ['number' => $this->required($row, 'number')],
            [
                'invoice_id' => $invoice->getKey(),
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'payment_method' => $method,
                'source_bank_name' => $this->value($row, 'source_bank_name'),
                'destination_bank_name' => $this->value($row, 'destination_bank_name'),
                'destination_account_number' => $this->value($row, 'destination_account_number'),
                'destination_account_holder' => $this->value($row, 'destination_account_holder'),
                'reference_number' => $this->value($row, 'reference_number'),
                'status' => $status,
                'created_by' => $actor->getKey(),
                'verified_at' => $status === PaymentStatus::Verified
                    ? ($this->dateTime($row, 'verified_at') ?? $this->dateTime($row, 'payment_date'))
                    : null,
                'notes' => $this->value($row, 'notes'),
            ],
        );

        if ($status === PaymentStatus::Verified) {
            $verifiedAmount = (float) $invoice->payments()
                ->where('status', PaymentStatus::Verified->value)
                ->sum('amount');

            if ($verifiedAmount + 0.01 >= (float) $invoice->payable_amount) {
                $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();
                $purchaseOrder = $invoice->purchaseOrder()->firstOrFail();
                $closedAt = $this->dateTime($row, 'verified_at')
                    ?? $this->dateTime($row, 'payment_date')
                    ?? now();

                $purchaseOrder->forceFill([
                    'status' => PurchaseOrderStatus::Closed,
                    'paid_at' => $closedAt,
                    'closed_at' => $closedAt,
                ])->save();
            } else {
                $invoice->forceFill(['status' => InvoiceStatus::PartiallyPaid])->save();
            }
        }

        return $payment->refresh();
    }

    private function resolvePurchaseRequestForPurchaseOrder(
        LegacyImportBatch $batch,
        array $row,
        User $actor,
        SppgKitchen $kitchen,
        string $purchaseOrderNumber,
    ): PurchaseRequest {
        $fallbackNumber = Str::startsWith($purchaseOrderNumber, 'LEGACY-PO-')
            ? 'LEGACY-PR-'.Str::after($purchaseOrderNumber, 'LEGACY-PO-')
            : 'LEGACY-PR-'.$purchaseOrderNumber;
        $number = $this->value($row, 'purchase_request_number') ?: $fallbackNumber;
        $existing = PurchaseRequest::query()->where('number', $number)->first();

        if ($existing !== null) {
            return $existing;
        }

        $request = PurchaseRequest::query()->create([
            'number' => $number,
            'sppg_kitchen_id' => $kitchen->getKey(),
            'requested_by' => $actor->getKey(),
            'status' => PurchaseRequestStatus::PoGenerated,
            'submitted_at' => $this->dateTime($row, 'submitted_at')
                ?? $this->dateTime($row, 'order_date')
                ?? now(),
            'approved_at' => $this->dateTime($row, 'approved_at')
                ?? $this->dateTime($row, 'order_date')
                ?? now(),
            'notes' => 'Synthetic upstream record for legacy migration; workflow was not replayed.',
        ]);

        $this->recordSyntheticProvenance(
            $batch,
            $request,
            $row,
            'Purchase Request placeholder dibuat untuk menjaga relasi transaksi legacy yang mulai dari PO atau tahap sesudahnya.',
        );

        return $request;
    }

    private function resolvePurchaseRequestItem(
        LegacyImportBatch $batch,
        array $row,
        PurchaseRequest $request,
        Product $product,
        Unit $unit,
        float $quantity,
        float $unitPrice = 0,
    ): PurchaseRequestItem {
        $existing = PurchaseRequestItem::query()
            ->where('purchase_request_id', $request->getKey())
            ->where('product_id', $product->getKey())
            ->where('unit_id', $unit->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $item = PurchaseRequestItem::query()->create([
            'purchase_request_id' => $request->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $unit->getKey(),
            'description' => $this->value($row, 'item_description'),
            'requested_qty' => max(0.0001, $quantity),
            'estimated_unit_price' => $unitPrice,
            'estimated_total' => round(max(0.0001, $quantity) * $unitPrice, 2),
            'notes' => 'Synthetic upstream item for legacy migration.',
        ]);

        $this->recordSyntheticProvenance(
            $batch,
            $item,
            $row,
            'Purchase Request item placeholder dibuat dari item transaksi downstream.',
        );

        return $item;
    }

    private function resolvePurchaseOrderForDownstream(
        LegacyImportBatch $batch,
        array $row,
        User $actor,
        PurchaseOrderStatus $syntheticStatus,
        string $orderDate,
        float $amount,
        string $reason,
    ): PurchaseOrder {
        $number = $this->value($row, 'purchase_order_number') ?: 'LEGACY-PO-'.$this->required($row, 'number');
        $existing = PurchaseOrder::query()->where('number', $number)->first();

        if ($existing !== null) {
            return $existing;
        }

        $supplierCode = $this->required($row, 'supplier_code');
        $supplier = Supplier::query()->where('code', $supplierCode)->first();

        if ($supplier === null) {
            throw new DomainException("Supplier {$supplierCode} tidak ditemukan. Import master supplier terlebih dahulu.");
        }

        $kitchen = $this->kitchen($batch, $row);
        $request = $this->resolvePurchaseRequestForPurchaseOrder($batch, $row, $actor, $kitchen, $number);

        $purchaseOrder = PurchaseOrder::query()->create([
            'number' => $number,
            'supplier_id' => $supplier->getKey(),
            'sppg_kitchen_id' => $kitchen->getKey(),
            'purchase_request_id' => $request->getKey(),
            'order_date' => $orderDate,
            'subtotal' => $amount,
            'total_amount' => $amount,
            'status' => $syntheticStatus,
            'approved_at' => $this->dateTime($row, 'approved_at')
                ?? $this->dateTime($row, 'invoice_date')
                ?? $this->dateTime($row, 'received_at')
                ?? now(),
            'issued_at' => $this->dateTime($row, 'issued_at')
                ?? $this->dateTime($row, 'invoice_date')
                ?? $this->dateTime($row, 'received_at')
                ?? now(),
            'notes' => 'Synthetic upstream record for legacy migration; workflow was not replayed.',
            'created_by' => $actor->getKey(),
        ]);

        $this->recordSyntheticProvenance($batch, $purchaseOrder, $row, $reason);

        return $purchaseOrder;
    }

    private function resolvePurchaseOrderItemForDownstream(
        LegacyImportBatch $batch,
        array $row,
        User $actor,
        PurchaseOrder $purchaseOrder,
        Product $product,
        Unit $unit,
    ): PurchaseOrderItem {
        $existing = PurchaseOrderItem::query()
            ->where('purchase_order_id', $purchaseOrder->getKey())
            ->where('product_id', $product->getKey())
            ->where('unit_id', $unit->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $quantity = $this->number(
            $row,
            'ordered_qty',
            $this->number($row, 'planned_qty', $this->number($row, 'received_qty', 1)),
        );
        $quantity = max(0.0001, $quantity);
        $unitPrice = $this->number($row, 'unit_price', 0);
        $request = $purchaseOrder->purchaseRequest()->firstOrFail();
        $requestItem = $this->resolvePurchaseRequestItem(
            $batch,
            $row,
            $request,
            $product,
            $unit,
            $quantity,
            $unitPrice,
        );

        $allocation = PurchaseAllocation::query()
            ->where('purchase_request_item_id', $requestItem->getKey())
            ->where('supplier_id', $purchaseOrder->supplier_id)
            ->first();

        if ($allocation === null) {
            $allocation = PurchaseAllocation::query()->create([
                'purchase_request_item_id' => $requestItem->getKey(),
                'supplier_id' => $purchaseOrder->supplier_id,
                'allocated_qty' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => round($quantity * $unitPrice, 2),
                'status' => PurchaseAllocationStatus::PoGenerated,
                'notes' => 'Synthetic upstream allocation for legacy migration.',
                'allocated_by' => $actor->getKey(),
                'allocated_at' => now(),
            ]);

            $this->recordSyntheticProvenance(
                $batch,
                $allocation,
                $row,
                'Allocation placeholder dibuat agar item penerimaan legacy tetap terhubung ke PO.',
            );
        }

        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->getKey(),
            'purchase_request_item_id' => $requestItem->getKey(),
            'purchase_allocation_id' => $allocation->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $unit->getKey(),
            'product_name_snapshot' => $product->name,
            'description_snapshot' => $this->value($row, 'item_description'),
            'unit_name_snapshot' => $unit->symbol ?: $unit->name,
            'ordered_qty' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => round($quantity * $unitPrice, 2),
            'delivered_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);

        $this->recordSyntheticProvenance(
            $batch,
            $item,
            $row,
            'PO item placeholder dibuat dari item penerimaan legacy.',
        );

        return $item;
    }

    private function resolveInvoiceForPayment(
        LegacyImportBatch $batch,
        array $row,
        User $actor,
        string $paymentDate,
        float $amount,
    ): Invoice {
        $number = $this->value($row, 'invoice_number') ?: 'LEGACY-INV-'.$this->required($row, 'number');
        $existing = Invoice::query()->where('number', $number)->first();

        if ($existing !== null) {
            return $existing;
        }

        $invoiceDate = $this->date($row, 'invoice_date') ?? $paymentDate;
        $payable = $this->number($row, 'payable_amount', $this->number($row, 'invoice_amount', $amount));
        $purchaseOrder = $this->resolvePurchaseOrderForDownstream(
            $batch,
            $row,
            $actor,
            PurchaseOrderStatus::Invoiced,
            $this->date($row, 'order_date') ?? $invoiceDate,
            $this->number($row, 'po_amount', $payable),
            'Pembayaran diimport tanpa PO upstream yang sudah tersedia.',
        );

        $invoice = Invoice::query()->create([
            'number' => $number,
            'supplier_invoice_number' => $this->value($row, 'supplier_invoice_number'),
            'purchase_order_id' => $purchaseOrder->getKey(),
            'supplier_id' => $purchaseOrder->supplier_id,
            'sppg_kitchen_id' => $purchaseOrder->sppg_kitchen_id,
            'invoice_date' => $invoiceDate,
            'due_date' => $this->date($row, 'due_date'),
            'po_amount' => $this->number($row, 'po_amount', (float) $purchaseOrder->total_amount),
            'adjustment_amount' => 0,
            'withholding_tax_amount' => 0,
            'total_amount' => $payable,
            'payable_amount' => $payable,
            'status' => InvoiceStatus::Approved,
            'issued_at' => $this->dateTime($row, 'issued_at') ?? Carbon::parse($invoiceDate),
            'approved_at' => $this->dateTime($row, 'approved_at') ?? Carbon::parse($invoiceDate),
            'notes' => 'Synthetic upstream record for legacy migration; workflow was not replayed.',
            'created_by' => $actor->getKey(),
        ]);

        $this->recordSyntheticProvenance(
            $batch,
            $invoice,
            $row,
            'Invoice placeholder dibuat untuk pembayaran legacy yang masuk tanpa invoice upstream terimport.',
        );

        return $invoice;
    }

    private function recordSyntheticProvenance(
        LegacyImportBatch $batch,
        Model $sourceable,
        array $row,
        string $reason,
    ): void {
        DataProvenance::query()->updateOrCreate(
            [
                'sourceable_type' => $sourceable->getMorphClass(),
                'sourceable_id' => $sourceable->getKey(),
                'legacy_import_batch_id' => $batch->getKey(),
                'source_row' => (int) ($row['_source_row'] ?? 0),
            ],
            [
                'provenance_type' => 'legacy_import_synthetic',
                'source_file' => $batch->original_filename,
                'source_sheet' => $this->value($row, '_source_sheet'),
                'source_key' => $sourceable->getAttribute('number')
                    ?: $sourceable->getAttribute('code'),
                'metadata' => [
                    'import_type' => $batch->import_type->value,
                    'workflow_replayed' => false,
                    'synthetic_record' => true,
                    'synthetic_reason' => $reason,
                    'historical_actor_may_be_unknown' => true,
                ],
            ],
        );
    }

    private function recordProvenance(LegacyImportBatch $batch, Model $sourceable, array $row): void
    {
        DataProvenance::query()->updateOrCreate(
            [
                'sourceable_type' => $sourceable->getMorphClass(),
                'sourceable_id' => $sourceable->getKey(),
                'legacy_import_batch_id' => $batch->getKey(),
                'source_row' => (int) ($row['_source_row'] ?? 0),
            ],
            [
                'provenance_type' => 'legacy_import',
                'source_file' => $batch->original_filename,
                'source_sheet' => $this->value($row, '_source_sheet'),
                'source_key' => $this->value($row, 'number') ?: $this->value($row, 'code'),
                'metadata' => [
                    'import_type' => $batch->import_type->value,
                    'workflow_replayed' => false,
                    'historical_actor_may_be_unknown' => true,
                ],
            ],
        );
    }

    private function assertColumns(LegacyImportType $type, array $rows): void
    {
        if ($rows === []) {
            throw new DomainException('File import tidak memiliki data.');
        }

        $columns = array_keys($rows[0]);
        $missing = array_values(array_diff($type->requiredColumns(), $columns));

        if ($missing !== []) {
            throw new DomainException('Kolom wajib belum tersedia: '.implode(', ', $missing).'.');
        }
    }

    private function kitchen(LegacyImportBatch $batch, array $row): SppgKitchen
    {
        return SppgKitchen::query()
            ->where('organization_id', $batch->organization_id)
            ->where('code', $this->required($row, 'kitchen_code'))
            ->firstOrFail();
    }

    private function unit(array $row, ?int $fallbackUnitId = null): Unit
    {
        $code = $this->value($row, 'unit_code');

        if (filled($code)) {
            return Unit::query()->where('code', $code)->firstOrFail();
        }

        if ($fallbackUnitId !== null) {
            return Unit::query()->findOrFail($fallbackUnitId);
        }

        throw new DomainException('unit_code wajib diisi untuk baris item ini.');
    }

    private function required(array $row, string $key): string
    {
        $value = $this->value($row, $key);

        if (blank($value)) {
            throw new DomainException("Kolom {$key} wajib diisi.");
        }

        return (string) $value;
    }

    private function value(array $row, string $key): mixed
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? trim($value) : $value;
    }

    private function number(array $row, string $key, float $default): float
    {
        return $this->nullableNumber($row, $key) ?? $default;
    }

    private function nullableNumber(array $row, string $key): ?float
    {
        $value = $this->value($row, $key);

        if (blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_ireplace(['rp', ' '], '', (string) $value);

        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $normalized)) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (! is_numeric($normalized)) {
            throw new DomainException("Nilai {$key} bukan angka yang valid.");
        }

        return (float) $normalized;
    }

    private function date(array $row, string $key): ?string
    {
        $value = $this->value($row, $key);

        if (blank($value)) {
            return null;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function requiredDate(array $row, string $key): string
    {
        return $this->date($row, $key)
            ?? throw new DomainException("Kolom {$key} wajib diisi.");
    }

    private function dateTime(array $row, string $key): ?Carbon
    {
        $value = $this->value($row, $key);

        if (blank($value)) {
            return null;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            $serial = (float) $value;
            $days = (int) floor($serial);
            $seconds = (int) round(($serial - $days) * 86400);

            return Carbon::create(1899, 12, 30)->addDays($days)->addSeconds($seconds);
        }

        return Carbon::parse((string) $value);
    }

    private function requiredDateTime(array $row, string $key): Carbon
    {
        return $this->dateTime($row, $key)
            ?? throw new DomainException("Kolom {$key} wajib diisi.");
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enumValue(string $enum, mixed $value): BackedEnum
    {
        $normalized = Str::snake(Str::lower(trim((string) $value)));
        $case = $enum::tryFrom($normalized);

        if ($case === null) {
            throw new DomainException("Nilai status/metode '{$value}' tidak dikenali.");
        }

        return $case;
    }
}
