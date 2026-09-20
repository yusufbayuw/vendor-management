<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestTemplateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_request_template_id',
        'product_id',
        'unit_id',
        'description',
        'quality_specification',
        'requested_qty',
        'estimated_unit_price',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requested_qty' => 'decimal:4',
            'estimated_unit_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestTemplate::class, 'purchase_request_template_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
