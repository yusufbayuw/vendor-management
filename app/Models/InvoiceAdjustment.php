<?php

namespace App\Models;

use App\Enums\InvoiceAdjustmentDirection;
use App\Enums\InvoiceAdjustmentStatus;
use App\Enums\InvoiceAdjustmentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'type',
        'direction',
        'description',
        'amount',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceAdjustmentType::class,
            'direction' => InvoiceAdjustmentDirection::class,
            'amount' => 'decimal:2',
            'status' => InvoiceAdjustmentStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
