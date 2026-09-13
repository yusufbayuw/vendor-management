<?php

namespace App\Models;

use App\Enums\PurchaseAllocationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_request_item_id',
        'supplier_id',
        'allocated_qty',
        'unit_price',
        'subtotal',
        'status',
        'notes',
        'allocated_by',
        'allocated_at',
    ];

    protected function casts(): array
    {
        return [
            'allocated_qty' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'status' => PurchaseAllocationStatus::class,
            'allocated_at' => 'datetime',
        ];
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    public function purchaseOrderItem(): HasOne
    {
        return $this->hasOne(PurchaseOrderItem::class);
    }
}
