<?php

namespace Tests\Feature\Foundation;

use App\Models\AuditLog;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audited_model_changes_are_recorded(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-AUDIT',
            'legal_name' => 'Supplier Audit',
            'email' => 'audit@example.test',
            'phone' => '08123456789',
        ]);

        $supplier->update(['phone' => '08120000000']);

        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', $supplier->getMorphClass())
            ->where('auditable_id', $supplier->id)
            ->where('event', 'updated')
            ->exists());
    }
}
