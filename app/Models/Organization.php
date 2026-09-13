<?php

namespace App\Models;

use App\Enums\OperationalProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'operational_profile',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'operational_profile' => OperationalProfile::class,
            'is_active' => 'boolean',
        ];
    }

    public function kitchens(): HasMany
    {
        return $this->hasMany(SppgKitchen::class);
    }

    public function governancePolicies(): HasMany
    {
        return $this->hasMany(GovernancePolicy::class);
    }
}
