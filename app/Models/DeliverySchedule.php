<?php

namespace App\Models;

use App\Enums\DeliveryScheduleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliverySchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'purchase_order_id',
        'planned_delivery_at',
        'estimated_arrival_at',
        'status',
        'driver_name',
        'driver_phone',
        'vehicle_number',
        'delivery_note_number',
        'delivery_note_file',
        'supplier_notes',
        'kitchen_notes',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'departed_at',
    ];

    protected function casts(): array
    {
        return [
            'planned_delivery_at' => 'datetime',
            'estimated_arrival_at' => 'datetime',
            'status' => DeliveryScheduleStatus::class,
            'confirmed_at' => 'datetime',
            'departed_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryScheduleItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
