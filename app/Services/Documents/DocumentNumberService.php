<?php

namespace App\Services\Documents;

use App\Models\DocumentSequence;
use App\Models\SppgKitchen;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function next(string $documentType, SppgKitchen $kitchen, ?Carbon $date = null): string
    {
        $date ??= now();
        $type = strtoupper(trim($documentType));
        $year = $date->year;

        return DB::transaction(function () use ($type, $kitchen, $year): string {
            DocumentSequence::query()->firstOrCreate(
                [
                    'document_type' => $type,
                    'sppg_kitchen_id' => $kitchen->getKey(),
                    'year' => $year,
                ],
                ['last_number' => 0],
            );

            $sequence = DocumentSequence::query()
                ->where('document_type', $type)
                ->where('sppg_kitchen_id', $kitchen->getKey())
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->increment('last_number');
            $sequence->refresh();

            return sprintf('%s/%s/%d/%06d', $type, $kitchen->code, $year, $sequence->last_number);
        }, 3);
    }
}
