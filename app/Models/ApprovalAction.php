<?php

namespace App\Models;

use App\Enums\ApprovalActionType;
use App\Enums\ApprovalDecisionSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'approval_request_id',
        'actor_id',
        'action',
        'is_self_approval',
        'decision_source',
        'comments',
        'override_reason',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => ApprovalActionType::class,
            'is_self_approval' => 'boolean',
            'decision_source' => ApprovalDecisionSource::class,
            'acted_at' => 'datetime',
        ];
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
