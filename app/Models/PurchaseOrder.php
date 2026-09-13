<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'supplier_id',
        'sppg_kitchen_id',
        'purchase_request_id',
        'order_date',
        'delivery_start',
        'delivery_end',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'status',
        'revision_number',
        'approved_at',
        'approved_by',
        'issued_at',
        'acknowledged_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'delivery_start' => 'date',
            'delivery_end' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'status' => PurchaseOrderStatus::class,
            'revision_number' => 'integer',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(SppgKitchen::class, 'sppg_kitchen_id');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(PurchaseOrderResponse::class);
    }

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
    }
}
