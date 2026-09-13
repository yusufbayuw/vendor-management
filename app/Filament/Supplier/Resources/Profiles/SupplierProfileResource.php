<?php

namespace App\Filament\Supplier\Resources\Profiles;

use App\Actions\Supplier\SubmitSupplierAction;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Filament\Supplier\Resources\Profiles\Pages\ManageSupplierProfiles;
use App\Models\Supplier;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierProfileResource extends Resource
{
    protected static ?string $model = Supplier::class;
    protected static ?string $navigationLabel = 'Profil Perusahaan';
    protected static string | UnitEnum | null $navigationGroup = 'Perusahaan';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')->label('Nama Legal')->required(),
            TextInput::make('display_name')->label('Nama Dagang'),
            Select::make('supplier_type')->label('Jenis')->options([
                'company' => 'Perusahaan',
                'individual' => 'Perorangan',
                'cooperative' => 'Koperasi',
                'other' => 'Lainnya',
            ])->required(),
            TextInput::make('npwp')->label('NPWP'),
            TextInput::make('nib')->label('NIB'),
            TextInput::make('email')->label('Email')->email()->required(),
            TextInput::make('phone')->label('Telepon')->tel()->required(),
            TextInput::make('website')->label('Website')->url(),
            Textarea::make('address')->label('Alamat')->columnSpanFull(),
            TextInput::make('province_code')->label('Kode Provinsi'),
            TextInput::make('regency_code')->label('Kode Kabupaten/Kota'),
            TextInput::make('district_code')->label('Kode Kecamatan'),
            TextInput::make('village_code')->label('Kode Desa/Kelurahan'),
            TextInput::make('postal_code')->label('Kode Pos'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label('Kode'),
            TextColumn::make('display_name')->label('Supplier'),
            TextColumn::make('status')->badge()->formatStateUsing(
                fn ($state) => $state instanceof SupplierStatus ? $state->label() : SupplierStatus::tryFrom((string) $state)?->label(),
            ),
            TextColumn::make('email'),
            TextColumn::make('phone')->label('Telepon'),
            TextColumn::make('documents_count')->label('Dokumen'),
            TextColumn::make('products_count')->label('Produk'),
        ])->recordActions([
            EditAction::make()->visible(fn (Supplier $record) => static::canEdit($record)),
            Action::make('submit')->label('Ajukan Verifikasi')->color('primary')->requiresConfirmation()
                ->visible(fn (Supplier $record) => static::editableStatus($record) && static::owned($record))
                ->action(function (Supplier $record): void {
                    try {
                        app(SubmitSupplierAction::class)->execute($record);
                        Notification::make()->success()->title('Supplier diajukan untuk verifikasi.')->send();
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

    public static function canCreate(): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

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
