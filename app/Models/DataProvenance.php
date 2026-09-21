<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DataProvenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'sourceable_type',
        'sourceable_id',
        'legacy_import_batch_id',
        'provenance_type',
        'source_file',
        'source_sheet',
        'source_row',
        'source_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_row' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LegacyImportBatch::class, 'legacy_import_batch_id');
    }
}
