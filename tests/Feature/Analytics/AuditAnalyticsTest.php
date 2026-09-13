<?php

namespace Tests\Feature\Analytics;

use App\Enums\SystemPermission;
use App\Models\AuditLog;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Analytics\AuditAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_auditor_can_open_audit_analytics_page(): void
    {
        $auditor = User::query()->where('email', 'auditor@example.test')->firstOrFail();

        $this->actingAs($auditor)
            ->get('/admin/analytics/audit')
            ->assertOk()
            ->assertSee('Audit Analytics')
            ->assertSee('Audit Trail')
            ->assertSee('Field Berubah')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_reports_permission_without_audit_permission_cannot_open_audit_analytics(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->assertTrue($user->can(SystemPermission::ReportsView->value));
        $this->assertFalse($user->can(SystemPermission::AuditView->value));

        $this->actingAs($user)
            ->get('/admin/analytics/audit')
            ->assertForbidden();
    }

    public function test_kitchen_scoped_auditor_only_sees_auditable_records_from_accessible_kitchen(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $user->givePermissionTo(SystemPermission::AuditView->value);

        $bandungRequest = PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->firstOrFail();
        $cimahiRequest = PurchaseRequest::query()->where('number', 'PR-DEMO-DRAFT-001')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-AYAM')->firstOrFail();

        $bandungLog = AuditLog::query()->create([
            'event' => 'updated',
            'auditable_type' => $bandungRequest->getMorphClass(),
            'auditable_id' => $bandungRequest->getKey(),
            'old_values' => ['notes' => 'lama'],
            'new_values' => ['notes' => 'baru'],
            'occurred_at' => now(),
        ]);
        $cimahiLog = AuditLog::query()->create([
            'event' => 'updated',
            'auditable_type' => $cimahiRequest->getMorphClass(),
            'auditable_id' => $cimahiRequest->getKey(),
            'old_values' => ['notes' => 'lama'],
            'new_values' => ['notes' => 'baru'],
            'occurred_at' => now(),
        ]);
        $supplierLog = AuditLog::query()->create([
            'event' => 'updated',
            'auditable_type' => $supplier->getMorphClass(),
            'auditable_id' => $supplier->getKey(),
            'old_values' => ['phone' => 'lama'],
            'new_values' => ['phone' => 'baru'],
            'occurred_at' => now(),
        ]);

        $visibleIds = app(AuditAnalyticsService::class)
            ->query($user)
            ->whereIn('audit_logs.id', [$bandungLog->id, $cimahiLog->id, $supplierLog->id])
            ->pluck('audit_logs.id');

        $this->assertTrue($visibleIds->contains($bandungLog->id));
        $this->assertFalse($visibleIds->contains($cimahiLog->id));
        $this->assertFalse($visibleIds->contains($supplierLog->id));
    }

    public function test_changed_fields_are_derived_without_exposing_raw_payload_as_primary_metric(): void
    {
        $log = new AuditLog([
            'old_values' => ['phone' => 'a', 'notes' => 'x'],
            'new_values' => ['phone' => 'b', 'status' => 'active'],
        ]);

        $service = app(AuditAnalyticsService::class);

        $this->assertSame(3, $service->changedFieldCount($log));
        $this->assertSame('notes, phone, status', $service->changedFieldsLabel($log));
    }
}
