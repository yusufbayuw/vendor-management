<?php

namespace App\Models;

use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentDiscrepancy extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'purchase_order_item_id',
        'goods_receipt_id',
        'goods_receipt_item_id',
        'type',
        'expected_qty',
        'actual_qty',
        'variance_qty',
        'description',
        'resolution',
        'resolution_notes',
        'status',
        'approved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DiscrepancyType::class,
            'expected_qty' => 'decimal:4',
            'actual_qty' => 'decimal:4',
            'variance_qty' => 'decimal:4',
            'resolution' => DiscrepancyResolution::class,
            'status' => DiscrepancyStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
