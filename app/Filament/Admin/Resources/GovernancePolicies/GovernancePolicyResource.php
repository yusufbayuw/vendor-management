<?php

namespace App\Filament\Admin\Resources\GovernancePolicies;

use App\Enums\GovernanceProcess;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\GovernancePolicies\Pages\ManageGovernancePolicies;
use App\Models\GovernancePolicy;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class GovernancePolicyResource extends Resource
{
    protected static ?string $model = GovernancePolicy::class;

    protected static ?string $navigationLabel = 'Kebijakan Approval';

    protected static ?string $modelLabel = 'kebijakan approval';

    protected static ?string $pluralModelLabel = 'kebijakan approval';

    protected static string|UnitEnum|null $navigationGroup = 'Administrasi';

    protected static ?int $navigationSort = 92;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')
                ->label('Organisasi')
                ->options(static function (): array {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return [];
                    }

                    return app(UserAccessService::class)
                        ->applyOrganizationScope(Organization::query()->orderBy('name'), $user)
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->preload()
                ->required(),
            Select::make('process')
                ->label('Proses')
                ->options(collect(GovernanceProcess::cases())->mapWithKeys(
                    static fn (GovernanceProcess $process): array => [$process->value => $process->label()],
                )->all())
                ->required(),
            TextInput::make('amount_threshold')
                ->label('Berlaku mulai nominal')
                ->numeric()
                ->prefix('Rp')
                ->minValue(0)
                ->helperText('Kosongkan untuk aturan default proses. Jika ada beberapa threshold, threshold tertinggi yang masih memenuhi nominal transaksi dipakai.'),
            TextInput::make('minimum_approvers')
                ->label('Minimum approver')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(10)
                ->default(1)
                ->required(),
            Toggle::make('self_approval_allowed')
                ->label('Izinkan self-approval')
                ->helperText('Jika aktif, pembuat transaksi boleh menjadi approver bila memiliki permission yang sesuai.'),
            Toggle::make('requires_override_reason')
                ->label('Wajib alasan override')
                ->helperText('Cocok untuk organisasi lean saat pemisahan tugas tidak dapat dipenuhi.'),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')->label('Organisasi')->searchable()->sortable(),
                TextColumn::make('process')
                    ->label('Proses')
                    ->formatStateUsing(static fn ($state): string => $state instanceof GovernanceProcess ? $state->label() : (GovernanceProcess::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->searchable(),
                TextColumn::make('amount_threshold')
                    ->label('Threshold')
                    ->money('IDR', locale: 'id')
                    ->placeholder('Default'),
                TextColumn::make('minimum_approvers')->label('Approver')->sortable(),
                IconColumn::make('self_approval_allowed')->label('Self Approval')->boolean(),
                IconColumn::make('requires_override_reason')->label('Alasan Override')->boolean(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('organization_id')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('organization');
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $organizationIds = app(UserAccessService::class)
            ->applyOrganizationScope(Organization::query(), $user)
            ->pluck('id');

        return $query->whereIn('organization_id', $organizationIds);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::GovernanceManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $record instanceof GovernancePolicy
            && $user->can(SystemPermission::GovernanceManage->value)
            && app(UserAccessService::class)->canAccessOrganization($user, $record->organization_id);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageGovernancePolicies::route('/')];
    }
}
