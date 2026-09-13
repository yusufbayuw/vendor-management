<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'purchase_request_item_id',
        'purchase_allocation_id',
        'product_id',
        'unit_id',
        'product_name_snapshot',
        'description_snapshot',
        'unit_name_snapshot',
        'ordered_qty',
        'unit_price',
        'subtotal',
        'delivered_qty',
        'accepted_qty',
        'rejected_qty',
    ];

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'delivered_qty' => 'decimal:4',
            'accepted_qty' => 'decimal:4',
            'rejected_qty' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(PurchaseAllocation::class, 'purchase_allocation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function deliveryScheduleItems(): HasMany
    {
        return $this->hasMany(DeliveryScheduleItem::class);
    }

    public function goodsReceiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(FulfillmentDiscrepancy::class);
    }
}
