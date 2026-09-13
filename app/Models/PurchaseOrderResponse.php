<?php

namespace App\Models;

use App\Enums\PurchaseOrderResponseType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'response',
        'responded_by',
        'responded_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'response' => PurchaseOrderResponseType::class,
            'responded_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
