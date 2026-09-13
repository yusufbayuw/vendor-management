<?php

namespace Tests\Feature\Foundation;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Services\Audit\AuditService;
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

    public function test_audit_service_removes_secrets_and_masks_financial_identifiers_recursively(): void
    {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-SENSITIVE',
            'legal_name' => 'Supplier Sensitive',
            'email' => 'sensitive@example.test',
            'phone' => '08123456780',
        ]);

        $log = app(AuditService::class)->record(
            $supplier,
            'updated',
            [
                'account_number' => '1234567890',
                'npwp' => '1234567890123456',
                'password' => 'never-store-this',
                'metadata' => [
                    'api_token' => 'secret-token',
                    'destination_account_number' => '9988776655',
                ],
            ],
            [
                'account_number' => '1234560000',
                'secret' => 'also-never-store-this',
            ],
        );

        $this->assertSame('******7890', $log->old_values['account_number']);
        $this->assertSame('************3456', $log->old_values['npwp']);
        $this->assertArrayNotHasKey('password', $log->old_values);
        $this->assertArrayNotHasKey('api_token', $log->old_values['metadata']);
        $this->assertSame('******6655', $log->old_values['metadata']['destination_account_number']);
        $this->assertSame('******0000', $log->new_values['account_number']);
        $this->assertArrayNotHasKey('secret', $log->new_values);
    }
}
