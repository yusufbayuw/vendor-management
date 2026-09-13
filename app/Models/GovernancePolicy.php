<?php

namespace App\Models;

use App\Enums\GovernanceProcess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernancePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'process',
        'amount_threshold',
        'self_approval_allowed',
        'minimum_approvers',
        'requires_override_reason',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'process' => GovernanceProcess::class,
            'amount_threshold' => 'decimal:2',
            'self_approval_allowed' => 'boolean',
            'minimum_approvers' => 'integer',
            'requires_override_reason' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
