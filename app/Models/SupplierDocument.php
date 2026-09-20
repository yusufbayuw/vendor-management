<?php

namespace App\Models;

use App\Enums\SupplierDocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'document_type',
        'document_number',
        'issued_at',
        'expires_at',
        'file_path',
        'status',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'verification_note',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'status' => SupplierDocumentStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
