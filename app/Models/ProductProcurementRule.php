<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductProcurementRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity_tolerance_percentage',
        'requires_expiry_date',
        'requires_batch_number',
        'requires_temperature',
        'requires_photo',
        'requires_weight_photo',
    ];

    protected function casts(): array
    {
        return [
            'quantity_tolerance_percentage' => 'decimal:2',
            'requires_expiry_date' => 'boolean',
            'requires_batch_number' => 'boolean',
            'requires_temperature' => 'boolean',
            'requires_photo' => 'boolean',
            'requires_weight_photo' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
