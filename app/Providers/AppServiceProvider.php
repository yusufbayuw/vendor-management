<?php

namespace App\Providers;

use App\Contracts\OtpChannel;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\DeliverySchedule;
use App\Models\FulfillmentDiscrepancy;
use App\Models\GoodsReceipt;
use App\Models\GovernancePolicy;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Payment;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Observers\AuditableObserver;
use App\Policies\RolePolicy;
use App\Services\Auth\LogOtpChannel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OtpChannel::class, function ($app): OtpChannel {
            return match (config('phone-verification.driver', 'log')) {
                'log' => $app->make(LogOtpChannel::class),
                default => throw new InvalidArgumentException('OTP_CHANNEL belum didukung.'),
            };
        });
    }

    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);

        foreach ($this->auditedModels() as $model) {
            $model::observe(AuditableObserver::class);
        }
    }

    /** @return array<class-string> */
    private function auditedModels(): array
    {
        return [
            Supplier::class,
            SupplierBankAccount::class,
            GovernancePolicy::class,
            PurchaseRequest::class,
            ApprovalRequest::class,
            ApprovalAction::class,
            PurchaseAllocation::class,
            PurchaseOrder::class,
            DeliverySchedule::class,
            GoodsReceipt::class,
            FulfillmentDiscrepancy::class,
            Invoice::class,
            InvoiceAdjustment::class,
            Payment::class,
        ];
    }
}
