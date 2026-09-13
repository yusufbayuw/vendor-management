<?php

namespace App\Models;

use App\Enums\GoodsReceiptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'purchase_order_id',
        'delivery_schedule_id',
        'supplier_id',
        'sppg_kitchen_id',
        'received_at',
        'received_by',
        'supplier_representative',
        'driver_name',
        'vehicle_number',
        'delivery_note_number',
        'status',
        'notes',
        'inspected_at',
        'inspected_by',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'status' => GoodsReceiptStatus::class,
            'inspected_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function deliverySchedule(): BelongsTo
    {
        return $this->belongsTo(DeliverySchedule::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(SppgKitchen::class, 'sppg_kitchen_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(GoodsReceiptAttachment::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(FulfillmentDiscrepancy::class);
    }
}
