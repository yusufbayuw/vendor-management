<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'supplier_invoice_number',
        'purchase_order_id',
        'supplier_id',
        'sppg_kitchen_id',
        'invoice_date',
        'due_date',
        'po_amount',
        'adjustment_amount',
        'withholding_tax_amount',
        'total_amount',
        'payable_amount',
        'status',
        'invoice_file',
        'issued_at',
        'approved_at',
        'approved_by',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'po_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'withholding_tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(SppgKitchen::class, 'sppg_kitchen_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InvoiceAdjustment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
    }
}
