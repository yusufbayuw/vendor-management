<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'product_id',
        'supplier_product_code',
        'minimum_order_qty',
        'maximum_order_qty',
        'lead_time_days',
        'indicative_price',
        'is_available',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'minimum_order_qty' => 'decimal:4',
            'maximum_order_qty' => 'decimal:4',
            'lead_time_days' => 'integer',
            'indicative_price' => 'decimal:2',
            'is_available' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
