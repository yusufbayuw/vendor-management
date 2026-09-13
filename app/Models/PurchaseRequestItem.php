<?php

namespace App\Models;

use App\Enums\PurchaseAllocationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_request_id',
        'product_id',
        'unit_id',
        'description',
        'quality_specification',
        'requested_qty',
        'estimated_unit_price',
        'estimated_total',
        'preferred_delivery_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_qty' => 'decimal:4',
            'estimated_unit_price' => 'decimal:2',
            'estimated_total' => 'decimal:2',
            'preferred_delivery_date' => 'date',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseAllocation::class)
            ->where('status', '!=', PurchaseAllocationStatus::Cancelled->value);
    }
}
