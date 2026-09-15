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
use App\Observers\TransactionNotificationObserver;
use App\Policies\RolePolicy;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\PriceAnomalyAnalyticsService;
use App\Services\Analytics\ProcessBottleneckAnalyticsService;
use App\Services\Analytics\ProcessPerformanceAnalyticsService;
use App\Services\Auth\LogOtpChannel;
use App\Support\UiNumber;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OtpChannel::class, function ($app): OtpChannel {
            $mode = (string) config('phone-verification.mode', 'manual');
            $driver = (string) config('phone-verification.driver', 'log');

            if ($app->environment('production') && $mode === 'otp' && $driver === 'log') {
                throw new InvalidArgumentException(
                    'OTP_CHANNEL=log tidak boleh digunakan saat PHONE_VERIFICATION_MODE=otp di production.',
                );
            }

            return match ($driver) {
                'log' => $app->make(LogOtpChannel::class),
                default => throw new InvalidArgumentException('OTP_CHANNEL belum didukung.'),
            };
        });

        $this->app->scoped(
            PriceAnomalyAnalyticsService::class,
            fn ($app): PriceAnomalyAnalyticsService => new PriceAnomalyAnalyticsService(
                $app->make(UserAccessService::class),
            ),
        );

        $this->app->scoped(
            ProcessBottleneckAnalyticsService::class,
            fn ($app): ProcessBottleneckAnalyticsService => new ProcessBottleneckAnalyticsService(
                $app->make(UserAccessService::class),
                $app->make(ProcessPerformanceAnalyticsService::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->configureUiFormats();

        Gate::policy(Role::class, RolePolicy::class);

        foreach ($this->auditedModels() as $model) {
            $model::observe(AuditableObserver::class);
        }

        foreach ($this->notificationModels() as $model) {
            $model::observe(TransactionNotificationObserver::class);
        }
    }

    private function configureUiFormats(): void
    {
        Number::useLocale('id_ID');

        Table::configureUsing(static function (Table $table): void {
            $table
                ->defaultDateDisplayFormat('d/m/Y')
                ->defaultDateTimeDisplayFormat('d/m/Y H:i')
                ->defaultTimeDisplayFormat('H:i');
        });

        Schema::configureUsing(static function (Schema $schema): void {
            $schema
                ->defaultDateDisplayFormat('d/m/Y')
                ->defaultDateTimeDisplayFormat('d/m/Y H:i')
                ->defaultTimeDisplayFormat('H:i');
        });

        DateTimePicker::configureUsing(static function (DateTimePicker $component): void {
            $component->displayFormat('d/m/Y H:i');
        });

        DatePicker::configureUsing(static function (DatePicker $component): void {
            $component->displayFormat('d/m/Y');
        });

        TimePicker::configureUsing(static function (TimePicker $component): void {
            $component->displayFormat('H:i');
        });

        TextInput::configureUsing(static function (TextInput $component): void {
            $component
                ->mask(static fn (TextInput $component) => $component->getType() === 'number'
                    ? UiNumber::maskFor($component->getName())
                    : null)
                ->formatStateUsing(static fn (mixed $state, TextInput $component): mixed => $component->getType() === 'number'
                    ? UiNumber::formatForInput($state, $component->getName())
                    : $state)
                ->mutateStateForValidationUsing(static fn (mixed $state, TextInput $component): mixed => $component->getType() === 'number'
                    ? UiNumber::normalizeForStorage($state, $component->getName())
                    : $state)
                ->dehydrateStateUsing(static fn (mixed $state, TextInput $component): mixed => $component->getType() === 'number'
                    ? UiNumber::normalizeForStorage($state, $component->getName())
                    : $state);
        });
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

    /** @return array<class-string> */
    private function notificationModels(): array
    {
        return [
            Supplier::class,
            PurchaseRequest::class,
            PurchaseOrder::class,
            GoodsReceipt::class,
            Invoice::class,
            Payment::class,
        ];
    }
}
