<?php

namespace App\Filament\Supplier\Resources\Documents;

use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Filament\Supplier\Resources\Documents\Pages\ManageSupplierDocuments;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierDocumentResource extends Resource
{
    protected static ?string $model = SupplierDocument::class;
    protected static ?string $navigationLabel = 'Dokumen Legal';
    protected static string | UnitEnum | null $navigationGroup = 'Perusahaan';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')->label('Supplier')->options(static::supplierOptions())->required(),
            Select::make('document_type')->label('Jenis Dokumen')->options([
                'nib' => 'NIB',
                'npwp' => 'NPWP',
                'halal_certificate' => 'Sertifikat Halal',
                'business_license' => 'Izin Usaha',
                'food_safety' => 'Sertifikat Keamanan Pangan',
                'domicile' => 'Surat Domisili',
                'other' => 'Lainnya',
            ])->required(),
            TextInput::make('document_number')->label('Nomor Dokumen')->maxLength(255),
            DatePicker::make('issued_at')->label('Tanggal Terbit')->native(false),
            DatePicker::make('expires_at')->label('Berlaku Sampai')->native(false)->afterOrEqual('issued_at'),
            FileUpload::make('file_path')->label('Dokumen')->disk('local')->directory('supplier-documents')->visibility('private')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->required(),
            Hidden::make('status')->default(SupplierDocumentStatus::Uploaded->value),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('supplier.display_name')->label('Supplier'),
            TextColumn::make('document_type')->label('Jenis')->searchable(),
            TextColumn::make('document_number')->label('Nomor')->searchable(),
            TextColumn::make('expires_at')->label('Berlaku Sampai')->date('d/m/Y'),
            TextColumn::make('status')->badge()->formatStateUsing(
                fn ($state) => str($state instanceof SupplierDocumentStatus ? $state->value : (string) $state)->replace('_', ' ')->title(),
            ),
            TextColumn::make('rejection_reason')->label('Catatan Verifikasi')->wrap()->toggleable(),
        ])->recordActions([
            EditAction::make()->visible(fn (SupplierDocument $record) => static::canEdit($record)),
            DeleteAction::make()->visible(fn (SupplierDocument $record) => static::canDelete($record)),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('supplier')->whereIn('supplier_id', static::supplierIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierProfileManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canManageProfile();
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof SupplierDocument
            && in_array($record->supplier_id, static::supplierIds(), true)
            && $record->status !== SupplierDocumentStatus::Verified
            && static::supplierEditable($record->supplier_id);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof SupplierDocument
            && in_array($record->status, [SupplierDocumentStatus::Uploaded, SupplierDocumentStatus::Rejected], true)
            && static::canEdit($record);
    }

    public static function canDeleteAny(): bool { return false; }

    private static function canManageProfile(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierProfileManage->value) ?? false;
    }

    private static function supplierEditable(int $supplierId): bool
    {
        return Supplier::query()->whereKey($supplierId)->whereIn('status', [SupplierStatus::Draft->value, SupplierStatus::RevisionRequired->value])->exists();
    }

    private static function supplierIds(): array
    {
        return auth()->user()?->suppliers()->wherePivot('is_active', true)->pluck('suppliers.id')->map(fn ($id) => (int) $id)->all() ?? [];
    }

    private static function supplierOptions(): array
    {
        return Supplier::query()->whereIn('id', static::supplierIds())->whereIn('status', [SupplierStatus::Draft->value, SupplierStatus::RevisionRequired->value])
            ->pluck('display_name', 'id')->all();
    }

    public static function getPages(): array
    {
        return ['index' => ManageSupplierDocuments::route('/')];
    }
}
