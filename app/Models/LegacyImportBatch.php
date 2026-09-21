<?php

namespace App\Models;

use App\Enums\LegacyImportType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegacyImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'import_type',
        'original_filename',
        'file_path',
        'status',
        'total_rows',
        'imported_rows',
        'failed_rows',
        'summary',
        'notes',
        'imported_by',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'import_type' => LegacyImportType::class,
            'summary' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function provenances(): HasMany
    {
        return $this->hasMany(DataProvenance::class);
    }
}
