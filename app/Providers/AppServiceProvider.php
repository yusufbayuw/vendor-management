<?php

namespace App\Providers;

use App\Contracts\OtpChannel;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\DeliverySchedule;
use App\Models\DataProvenance;
use App\Models\DeliveryScheduleItem;
use App\Models\FulfillmentDiscrepancy;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\GovernancePolicy;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\LegacyImportBatch;
use App\Models\Payment;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierDocument;
use App\Models\SupplierProduct;
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
            $mode = (string) config('phone-verification.mode', 'otp');
            $driver = (string) config('phone-verification.driver', 'log');

            if (! in_array($mode, ['otp', 'disabled'], true)) {
                throw new InvalidArgumentException('PHONE_VERIFICATION_MODE hanya mendukung otp atau disabled.');
            }

            if ($app->environment('production') && $mode !== 'otp') {
                throw new InvalidArgumentException('PHONE_VERIFICATION_MODE wajib otp di production.');
            }

            if ($app->environment('production') && $driver === 'log') {
                throw new InvalidArgumentException(
                    'OTP_CHANNEL=log tidak boleh digunakan untuk verifikasi supplier di production.',
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
            SupplierDocument::class,
            SupplierProduct::class,
            GovernancePolicy::class,
            LegacyImportBatch::class,
            DataProvenance::class,
            PurchaseRequest::class,
            PurchaseRequestItem::class,
            ApprovalRequest::class,
            ApprovalAction::class,
            PurchaseAllocation::class,
            PurchaseOrder::class,
            PurchaseOrderItem::class,
            DeliverySchedule::class,
            DeliveryScheduleItem::class,
            GoodsReceipt::class,
            GoodsReceiptItem::class,
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
