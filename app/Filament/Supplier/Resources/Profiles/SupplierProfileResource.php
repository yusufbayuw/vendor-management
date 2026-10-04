<?php

namespace App\Filament\Supplier\Resources\Profiles;

use App\Actions\Supplier\SubmitSupplierAction;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Filament\Supplier\Resources\Profiles\Pages\ManageSupplierProfiles;
use App\Models\Supplier;
use App\Services\Regions\IndonesiaRegionService;
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

class SupplierProfileResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationLabel = 'Data Usaha';

    protected static ?string $modelLabel = 'data usaha';

    protected static ?string $pluralModelLabel = 'data usaha';

    protected static string|UnitEnum|null $navigationGroup = 'Usaha Saya';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')
                ->label('Nama usaha / nama resmi')
                ->helperText('Isi sesuai NIB/NPWP jika ada. Jika belum punya, gunakan nama usaha yang biasa dipakai.')
                ->required(),
            TextInput::make('display_name')
                ->label('Nama toko / nama yang dikenal')
                ->helperText('Opsional. Kosongkan jika sama dengan nama usaha di atas.'),

            Select::make('supplier_type')
                ->label('Bentuk usaha')
                ->helperText('Pilih yang paling sesuai dengan kondisi usaha Anda.')
                ->options([
                    'individual' => 'Usaha perorangan / UMKM',
                    'company' => 'Perusahaan / badan usaha',
                    'cooperative' => 'Koperasi',
                    'other' => 'Lainnya',
                ])
                ->live()
                ->required(),

            TextInput::make('npwp')
                ->label('Nomor NPWP')
                ->helperText('Isi NPWP usaha atau pemilik usaha bila digunakan untuk usaha.')
                ->required(fn (Get $get): bool => ! (bool) $get('onboarding_exemptions.npwp')),

            Toggle::make('onboarding_exemptions.npwp')
                ->label('Saya belum memiliki NPWP')
                ->helperText('Aktifkan jika usaha Anda memang belum memiliki NPWP.')
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    if ($state) {
                        $set('npwp', null);
                    }
                }),

            TextInput::make('nib')
                ->label('Nomor Induk Berusaha / NIB')
                ->helperText('NIB biasanya terdapat pada dokumen OSS.')
                ->required(fn (Get $get): bool => in_array($get('supplier_type'), ['company', 'cooperative'], true)
                    && ! (bool) $get('onboarding_exemptions.nib')),

            Toggle::make('onboarding_exemptions.nib')
                ->label('Saya belum memiliki NIB')
                ->helperText('Aktifkan jika usaha Anda memang belum memiliki NIB.')
                ->visible(fn (Get $get): bool => in_array($get('supplier_type'), ['company', 'cooperative'], true))
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    if ($state) {
                        $set('nib', null);
                    }
                }),

            TextInput::make('email')
                ->label('Email (jika ada)')
                ->helperText('Tidak wajib. Email dipakai untuk pemberitahuan bila tersedia.')
                ->email(),

            TextInput::make('phone')->label('Nomor WhatsApp / HP')->helperText('Gunakan nomor yang aktif dan mudah dihubungi.')->tel()->required(),
            TextInput::make('website')->label('Website (jika ada)')->url(),

            Textarea::make('address')
                ->label('Alamat lengkap usaha')
                ->helperText('Tulis nama jalan, nomor, RT/RW, dan patokan bila diperlukan.')
                ->required()
                ->columnSpanFull(),

            Select::make('province_code')
                ->label('Provinsi')
                ->placeholder('Pilih provinsi')
                ->options(fn (): array => app(IndonesiaRegionService::class)->provinces())
                ->searchable()
                ->live()
                ->required()
                ->afterStateUpdated(function (Set $set): void {
                    $set('regency_code', null);
                    $set('district_code', null);
                    $set('village_code', null);
                }),

            Select::make('regency_code')
                ->label('Kabupaten / Kota')
                ->placeholder('Pilih kabupaten / kota')
                ->options(fn (Get $get): array => app(IndonesiaRegionService::class)->cities($get('province_code')))
                ->searchable()
                ->live()
                ->required()
                ->disabled(fn (Get $get): bool => blank($get('province_code')))
                ->afterStateUpdated(function (Set $set): void {
                    $set('district_code', null);
                    $set('village_code', null);
                }),

            Select::make('district_code')
                ->label('Kecamatan')
                ->placeholder('Pilih kecamatan')
                ->options(fn (Get $get): array => app(IndonesiaRegionService::class)->districts($get('regency_code')))
                ->searchable()
                ->live()
                ->required()
                ->disabled(fn (Get $get): bool => blank($get('regency_code')))
                ->afterStateUpdated(fn (Set $set) => $set('village_code', null)),

            Select::make('village_code')
                ->label('Desa / Kelurahan')
                ->placeholder('Pilih desa / kelurahan')
                ->options(fn (Get $get): array => app(IndonesiaRegionService::class)->villages($get('district_code')))
                ->searchable()
                ->required()
                ->disabled(fn (Get $get): bool => blank($get('district_code'))),

            TextInput::make('postal_code')->label('Kode pos')->maxLength(10),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label('Kode'),
            TextColumn::make('display_name')->label('Nama Usaha'),
            TextColumn::make('status')->badge()->formatStateUsing(
                fn ($state) => $state instanceof SupplierStatus ? $state->label() : SupplierStatus::tryFrom((string) $state)?->label(),
            ),
            TextColumn::make('email'),
            TextColumn::make('phone')->label('WhatsApp / HP'),
            TextColumn::make('documents_count')->label('Dokumen'),
            TextColumn::make('products_count')->label('Produk'),
        ])->recordActions([
            EditAction::make()->visible(fn (Supplier $record) => static::canEdit($record)),
            Action::make('submit')->label('Kirim untuk Diperiksa')->color('primary')->requiresConfirmation()
                ->visible(fn (Supplier $record) => static::editableStatus($record) && static::owned($record))
                ->action(function (Supplier $record): void {
                    try {
                        app(SubmitSupplierAction::class)->execute($record);
                        Notification::make()->success()->title('Data usaha sudah dikirim untuk diperiksa.')->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('id', static::supplierIds())->withCount(['documents', 'products']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierProfileManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Supplier && static::owned($record) && static::editableStatus($record);
    }

    public static function supplierIds(): array
    {
        return auth()->user()?->suppliers()->wherePivot('is_active', true)->pluck('suppliers.id')->map(fn ($id) => (int) $id)->all() ?? [];
    }

    private static function owned(Supplier $supplier): bool
    {
        return (auth()->user()?->can(SystemPermission::SupplierProfileManage->value) ?? false)
            && in_array((int) $supplier->getKey(), static::supplierIds(), true);
    }

    private static function editableStatus(Supplier $supplier): bool
    {
        return in_array($supplier->status, [SupplierStatus::Draft, SupplierStatus::RevisionRequired], true);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSupplierProfiles::route('/')];
    }
}
