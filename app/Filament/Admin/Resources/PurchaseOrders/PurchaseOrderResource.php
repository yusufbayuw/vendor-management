<?php

namespace App\Filament\Admin\Resources\PurchaseOrders;

use App\Actions\Procurement\ApprovePurchaseOrderAction;
use App\Actions\Procurement\IssuePurchaseOrderAction;
use App\Actions\Procurement\SubmitPurchaseOrderForApprovalAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\PurchaseOrders\Pages\ManagePurchaseOrders;
use App\Models\PurchaseOrder;
use App\Services\Access\UserAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;
    protected static ?string $navigationLabel = 'Purchase Order';
    protected static ?string $modelLabel = 'purchase order';
    protected static ?string $pluralModelLabel = 'purchase order';
    protected static string | UnitEnum | null $navigationGroup = 'Procurement';
    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Nomor PO')->searchable()->sortable(),
                TextColumn::make('supplier.display_name')->label('Supplier')->searchable()->sortable(),
                TextColumn::make('kitchen.name')->label('SPPG')->searchable()->sortable(),
                TextColumn::make('purchaseRequest.number')->label('PR')->searchable()->toggleable(),
                TextColumn::make('order_date')->label('Tanggal PO')->date('d/m/Y')->sortable(),
                TextColumn::make('delivery_start')->label('Mulai Kirim')->date('d/m/Y')->toggleable(),
                TextColumn::make('delivery_end')->label('Batas Kirim')->date('d/m/Y')->toggleable(),
                TextColumn::make('items_count')->label('Item')->sortable(),
                TextColumn::make('total_amount')->label('Total')->money('IDR')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => static::statusLabel($state))
                    ->color(static fn ($state): string => static::statusColor($state)),
            ])
            ->recordActions([
                Action::make('submitApproval')
                    ->label('Ajukan Approval')
                    ->visible(static fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Draft && static::canCreatePo($record))
                    ->requiresConfirmation()
                    ->action(static function (PurchaseOrder $record): void {
                        static::requirePermission(SystemPermission::PurchaseOrderCreate);
                        static::runDomainAction(fn () => app(SubmitPurchaseOrderForApprovalAction::class)->execute($record, auth()->user()), 'PO berhasil diajukan untuk approval.');
                    }),
                Action::make('approve')
                    ->label('Setujui')
                    ->color('success')
                    ->visible(static fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::PendingApproval && static::canApprovePo($record))
                    ->schema([
                        Textarea::make('comments')->label('Catatan approval')->rows(3),
                        Textarea::make('override_reason')->label('Alasan override (jika self approval)')->rows(3),
                    ])
                    ->action(static function (PurchaseOrder $record, array $data): void {
                        static::requirePermission(SystemPermission::PurchaseOrderApprove);
                        static::runDomainAction(
                            fn () => app(ApprovePurchaseOrderAction::class)->execute($record, auth()->user(), $data['comments'] ?? null, $data['override_reason'] ?? null),
                            'Keputusan approval PO berhasil disimpan.',
                        );
                    }),
                Action::make('issue')
                    ->label('Terbitkan PO')
                    ->color('primary')
                    ->visible(static fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Approved && static::canIssuePo($record))
                    ->requiresConfirmation()
                    ->action(static function (PurchaseOrder $record): void {
                        static::requirePermission(SystemPermission::PurchaseOrderIssue);
                        static::runDomainAction(fn () => app(IssuePurchaseOrderAction::class)->execute($record), 'PO berhasil diterbitkan dan siap dikonfirmasi supplier.');
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['supplier', 'kitchen', 'purchaseRequest'])
            ->withCount('items');
        $user = auth()->user();

        return $user
            ? app(UserAccessService::class)->applyKitchenOwnedScope($query, $user)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can(SystemPermission::PurchaseOrderCreate->value)
            || $user->can(SystemPermission::PurchaseOrderApprove->value)
            || $user->can(SystemPermission::PurchaseOrderIssue->value)
            || $user->can(SystemPermission::DeliverySchedule->value)
            || $user->can(SystemPermission::PurchaseOrderExceptionClose->value)
            || $user->can(SystemPermission::InvoiceReview->value)
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function canCreatePo(PurchaseOrder $record): bool
    {
        return static::hasScopedPermission($record, SystemPermission::PurchaseOrderCreate);
    }

    private static function canApprovePo(PurchaseOrder $record): bool
    {
        return static::hasScopedPermission($record, SystemPermission::PurchaseOrderApprove);
    }

    private static function canIssuePo(PurchaseOrder $record): bool
    {
        return static::hasScopedPermission($record, SystemPermission::PurchaseOrderIssue);
    }

    private static function hasScopedPermission(PurchaseOrder $record, SystemPermission $permission): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can($permission->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id);
    }

    private static function requirePermission(SystemPermission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }

    private static function runDomainAction(callable $action, string $successMessage): void
    {
        try {
            $action();
            Notification::make()->success()->title($successMessage)->send();
        } catch (DomainException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof PurchaseOrderStatus ? $state : PurchaseOrderStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseOrderStatus::Draft => 'Draft',
            PurchaseOrderStatus::PendingApproval => 'Menunggu Approval',
            PurchaseOrderStatus::Approved => 'Disetujui',
            PurchaseOrderStatus::Issued => 'Diterbitkan',
            PurchaseOrderStatus::Acknowledged => 'Dikonfirmasi Supplier',
            PurchaseOrderStatus::Scheduled => 'Terjadwal',
            PurchaseOrderStatus::PartiallyDelivered => 'Terkirim Sebagian',
            PurchaseOrderStatus::Fulfilled => 'Terpenuhi',
            PurchaseOrderStatus::PendingExceptionClosure => 'Menunggu Penutupan Exception',
            PurchaseOrderStatus::ClosedWithException => 'Ditutup dengan Exception',
            PurchaseOrderStatus::Invoiced => 'Ditagihkan',
            PurchaseOrderStatus::Paid => 'Dibayar',
            PurchaseOrderStatus::Closed => 'Selesai',
            PurchaseOrderStatus::Cancelled => 'Dibatalkan',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof PurchaseOrderStatus ? $state : PurchaseOrderStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseOrderStatus::Fulfilled, PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed => 'success',
            PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Scheduled, PurchaseOrderStatus::PartiallyDelivered, PurchaseOrderStatus::PendingExceptionClosure => 'warning',
            PurchaseOrderStatus::Cancelled => 'danger',
            PurchaseOrderStatus::ClosedWithException => 'info',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePurchaseOrders::route('/'),
        ];
    }
}
