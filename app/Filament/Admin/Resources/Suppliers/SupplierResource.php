<?php

namespace App\Filament\Admin\Resources\Suppliers;

use App\Actions\Supplier\ActivateSupplierWithOverrideAction;
use App\Actions\Supplier\ApproveSupplierAction;
use App\Actions\Supplier\RequestSupplierRevisionAction;
use App\Actions\Supplier\StartSupplierReviewAction;
use App\Actions\Supplier\SuspendSupplierAction;
use App\Enums\SupplierManagementMode;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Regions\IndonesiaRegionService;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationLabel = 'Supplier';

    protected static ?string $modelLabel = 'supplier';

    protected static ?string $pluralModelLabel = 'supplier';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Kode')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true)
                ->disabled(static fn (?Supplier $record): bool => $record?->status === SupplierStatus::Active),
            TextInput::make('legal_name')->label('Nama Legal')->required()->maxLength(255),
            TextInput::make('display_name')->label('Nama Supplier / Dagang')->maxLength(255),
            Select::make('supplier_type')
                ->label('Jenis')
                ->options([
                    'company' => 'Perusahaan',
                    'individual' => 'Perorangan',
                    'cooperative' => 'Koperasi',
                    'other' => 'Lainnya',
                ])
                ->default('company')
                ->required(),
            Select::make('management_mode')
                ->label('Mode Pengelolaan')
                ->options(collect(SupplierManagementMode::cases())->mapWithKeys(
                    static fn (SupplierManagementMode $mode): array => [$mode->value => $mode->label()],
                )->all())
                ->default(SupplierManagementMode::AdminManaged->value)
                ->required()
                ->disabled(static fn (?Supplier $record): bool => $record?->status === SupplierStatus::Active)
                ->helperText('Dikelola Admin cocok untuk supplier existing/tanpa akun portal.'),
            TextInput::make('npwp')->label('NPWP')->maxLength(40),
            TextInput::make('nib')->label('NIB')->maxLength(80),
            TextInput::make('email')->label('Email')->email()->maxLength(255),
            TextInput::make('phone')->label('Telepon')->tel()->maxLength(40),
            TextInput::make('website')->label('Website')->url()->maxLength(255),
            Textarea::make('address')->label('Alamat')->rows(3)->columnSpanFull(),
            Select::make('province_code')
                ->label('Provinsi')
                ->options(fn (): array => app(IndonesiaRegionService::class)->provinces())
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('regency_code', null);
                    $set('district_code', null);
                    $set('village_code', null);
                }),
            Select::make('regency_code')
                ->label('Kabupaten / Kota')
                ->options(fn (Get $get): array => app(IndonesiaRegionService::class)->cities($get('province_code')))
                ->searchable()
                ->live()
                ->disabled(fn (Get $get): bool => blank($get('province_code')))
                ->afterStateUpdated(function (Set $set): void {
                    $set('district_code', null);
                    $set('village_code', null);
                }),
            Select::make('district_code')
                ->label('Kecamatan')
                ->options(fn (Get $get): array => app(IndonesiaRegionService::class)->districts($get('regency_code')))
                ->searchable()
                ->live()
                ->disabled(fn (Get $get): bool => blank($get('regency_code')))
                ->afterStateUpdated(fn (Set $set) => $set('village_code', null)),
            Select::make('village_code')
                ->label('Desa / Kelurahan')
                ->options(fn (Get $get): array => app(IndonesiaRegionService::class)->villages($get('district_code')))
                ->searchable()
                ->disabled(fn (Get $get): bool => blank($get('district_code'))),
            TextInput::make('postal_code')->label('Kode Pos')->maxLength(10),
            Textarea::make('notes')->label('Catatan internal')->rows(3)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('display_name')->label('Nama Supplier')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('legal_name')->label('Nama Legal')->searchable()->toggleable(),
                TextColumn::make('management_mode')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => $state instanceof SupplierManagementMode ? $state->label() : (SupplierManagementMode::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(static fn ($state): string => ($state instanceof SupplierManagementMode ? $state : SupplierManagementMode::tryFrom((string) $state)) === SupplierManagementMode::AdminManaged ? 'info' : 'gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => $state instanceof SupplierStatus ? $state->label() : (SupplierStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(static fn ($state): string => match ($state instanceof SupplierStatus ? $state : SupplierStatus::tryFrom((string) $state)) {
                        SupplierStatus::Active => 'success',
                        SupplierStatus::Submitted, SupplierStatus::UnderReview => 'warning',
                        SupplierStatus::RevisionRequired => 'info',
                        SupplierStatus::Suspended, SupplierStatus::Rejected => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('operational_status')
                    ->label('Operasional')
                    ->badge()
                    ->state(static fn (Supplier $record): string => app(SupplierOperationalEligibilityService::class)->isOperationallyEligible($record) ? 'Siap' : 'Belum')
                    ->color(static fn (string $state): string => $state === 'Siap' ? 'success' : 'warning'),
                TextColumn::make('pic_phone_status')
                    ->label('Akun/PIC')
                    ->badge()
                    ->state(static function (Supplier $record): string {
                        if (app(SupplierOperationalEligibilityService::class)->portalIdentitySatisfied($record)) {
                            return 'Terverifikasi';
                        }

                        return app(SupplierOperationalEligibilityService::class)->hasExemption(
                            $record,
                            SupplierOperationalEligibilityService::PORTAL_IDENTITY_EXEMPTION,
                        ) ? 'Di-override' : 'Belum';
                    })
                    ->color(static fn (string $state): string => match ($state) {
                        'Terverifikasi' => 'success',
                        'Di-override' => 'info',
                        default => 'warning',
                    }),
                TextColumn::make('email')->label('Email')->searchable()->toggleable(),
                TextColumn::make('phone')->label('Telepon')->toggleable(),
                TextColumn::make('documents_count')->label('Dokumen')->sortable(),
                TextColumn::make('products_count')->label('Produk')->sortable(),
            ])
            ->recordActions([
                EditAction::make()->visible(static fn (Supplier $record): bool => static::canEdit($record)),
                Action::make('startReview')
                    ->label('Mulai Verifikasi')
                    ->visible(static fn (Supplier $record): bool => $record->status === SupplierStatus::Submitted && static::canVerify())
                    ->requiresConfirmation()
                    ->action(static function (Supplier $record): void {
                        static::requirePermission(SystemPermission::SupplierVerify);
                        static::runDomainAction(fn () => app(StartSupplierReviewAction::class)->execute($record), 'Verifikasi supplier dimulai.');
                    }),
                Action::make('requestRevision')
                    ->label('Minta Perbaikan')
                    ->color('warning')
                    ->visible(static fn (Supplier $record): bool => in_array($record->status, [SupplierStatus::Submitted, SupplierStatus::UnderReview], true) && static::canVerify())
                    ->schema([
                        Textarea::make('reason')->label('Catatan perbaikan')->required()->rows(4),
                    ])
                    ->action(static function (Supplier $record, array $data): void {
                        static::requirePermission(SystemPermission::SupplierVerify);
                        static::runDomainAction(fn () => app(RequestSupplierRevisionAction::class)->execute($record, $data['reason']), 'Permintaan perbaikan dikirim.');
                    }),
                Action::make('approve')
                    ->label('Setujui Supplier')
                    ->color('success')
                    ->visible(static fn (Supplier $record): bool => in_array($record->status, [SupplierStatus::Submitted, SupplierStatus::UnderReview], true) && static::canVerify())
                    ->requiresConfirmation()
                    ->action(static function (Supplier $record): void {
                        static::requirePermission(SystemPermission::SupplierVerify);
                        static::runDomainAction(fn () => app(ApproveSupplierAction::class)->execute($record, auth()->user()), 'Supplier berhasil diaktifkan.');
                    }),
                Action::make('activateOverride')
                    ->label('Aktifkan dengan Override')
                    ->color('warning')
                    ->visible(static fn (Supplier $record): bool => in_array($record->status, [
                        SupplierStatus::Draft,
                        SupplierStatus::Submitted,
                        SupplierStatus::UnderReview,
                        SupplierStatus::RevisionRequired,
                    ], true) && static::canVerify())
                    ->schema(static fn (Supplier $record): array => [
                        Toggle::make('bypass_documents')
                            ->label('Bypass dokumen legal')
                            ->default(! app(SupplierOperationalEligibilityService::class)->documentsSatisfied($record))
                            ->helperText('Gunakan bila dokumen belum tersedia/selesai diverifikasi tetapi operasional harus berjalan.'),
                        Toggle::make('bypass_portal_identity')
                            ->label('Bypass akun/PIC supplier')
                            ->default(! app(SupplierOperationalEligibilityService::class)->portalIdentitySatisfied($record))
                            ->helperText('Gunakan untuk supplier existing/tanpa akun portal atau PIC terverifikasi.'),
                        Textarea::make('reason')
                            ->label('Alasan override')
                            ->required()
                            ->rows(4)
                            ->helperText('Alasan, aktor, dan waktu override disimpan sebagai jejak audit aktivasi.'),
                    ])
                    ->action(static function (Supplier $record, array $data): void {
                        static::requirePermission(SystemPermission::SupplierVerify);
                        static::runDomainAction(fn () => app(ActivateSupplierWithOverrideAction::class)->execute(
                            $record,
                            auth()->user(),
                            $data['reason'],
                            (bool) ($data['bypass_documents'] ?? false),
                            (bool) ($data['bypass_portal_identity'] ?? false),
                        ), 'Supplier aktif dan dapat digunakan secara operasional.');
                    }),
                Action::make('suspend')
                    ->label('Suspend')
                    ->color('danger')
                    ->visible(static fn (Supplier $record): bool => $record->status === SupplierStatus::Active && (auth()->user()?->can(SystemPermission::SupplierSuspend->value) ?? false))
                    ->schema([
                        Textarea::make('reason')->label('Alasan penangguhan')->required()->rows(4),
                    ])
                    ->action(static function (Supplier $record, array $data): void {
                        static::requirePermission(SystemPermission::SupplierSuspend);
                        static::runDomainAction(fn () => app(SuspendSupplierAction::class)->execute($record, $data['reason']), 'Supplier ditangguhkan.');
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['approvalAttestation', 'documents', 'users.phoneVerificationCodes'])
            ->withCount(['documents', 'products']);
        $user = auth()->user();

        return $user
            ? app(UserAccessService::class)->applySupplierScope($query, $user)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierView->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierManage->value) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Supplier
            && (auth()->user()?->can(SystemPermission::SupplierManage->value) ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }

    private static function canVerify(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierVerify->value) ?? false;
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
}
