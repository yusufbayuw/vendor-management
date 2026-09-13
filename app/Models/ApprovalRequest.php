<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'approvable_type',
        'approvable_id',
        'process',
        'status',
        'requested_by',
        'amount',
        'required_approvers',
        'self_approval_allowed',
        'requires_override_reason',
        'requested_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'process' => GovernanceProcess::class,
            'status' => ApprovalStatus::class,
            'amount' => 'decimal:2',
            'required_approvers' => 'integer',
            'self_approval_allowed' => 'boolean',
            'requires_override_reason' => 'boolean',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class);
    }
}
