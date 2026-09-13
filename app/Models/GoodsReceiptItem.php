<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_receipt_id',
        'delivery_schedule_item_id',
        'purchase_order_item_id',
        'planned_qty',
        'received_qty',
        'accepted_qty',
        'rejected_qty',
        'variance_qty',
        'condition',
        'rejection_reason',
        'batch_number',
        'expiry_date',
        'temperature',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'planned_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'accepted_qty' => 'decimal:4',
            'rejected_qty' => 'decimal:4',
            'variance_qty' => 'decimal:4',
            'expiry_date' => 'date',
            'temperature' => 'decimal:2',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function deliveryScheduleItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryScheduleItem::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(FulfillmentDiscrepancy::class);
    }
}
