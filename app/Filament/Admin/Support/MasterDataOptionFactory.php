<?php

namespace App\Filament\Admin\Support;

use App\Enums\SystemPermission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

final class MasterDataOptionFactory
{
    public static function canManage(): bool
    {
        return auth()->user()?->can(SystemPermission::MasterDataManage->value) ?? false;
    }

    public static function category(Select $select): Select
    {
        return $select
            ->createOptionForm([
                TextInput::make('code')
                    ->label('Kode kategori')
                    ->required()
                    ->maxLength(50)
                    ->unique(table: ProductCategory::class, column: 'code'),
                TextInput::make('name')
                    ->label('Nama kategori')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->helperText(static fn (?string $state): ?string => MasterDataDuplicateGuard::hint(ProductCategory::class, $state)),
                Textarea::make('description')
                    ->label('Deskripsi')
                    ->rows(3),
            ])
            ->createOptionAction(
                fn (Action $action): Action => self::secureCreateAction($action, 'Buat kategori produk'),
            )
            ->createOptionUsing(static function (array $data): int {
                self::authorizeManage();
                MasterDataDuplicateGuard::assertNoExactName(ProductCategory::class, (string) $data['name']);

                return ProductCategory::query()->create([
                    'code' => Str::upper(trim((string) $data['code'])),
                    'name' => trim((string) $data['name']),
                    'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                    'is_active' => true,
                ])->getKey();
            });
    }

    public static function unit(Select $select): Select
    {
        return $select
            ->createOptionForm([
                TextInput::make('code')
                    ->label('Kode satuan')
                    ->required()
                    ->maxLength(30)
                    ->unique(table: Unit::class, column: 'code'),
                TextInput::make('name')
                    ->label('Nama satuan')
                    ->required()
                    ->maxLength(100)
                    ->live(onBlur: true)
                    ->helperText(static fn (?string $state): ?string => MasterDataDuplicateGuard::hint(Unit::class, $state)),
                TextInput::make('symbol')
                    ->label('Simbol')
                    ->required()
                    ->maxLength(20),
                TextInput::make('decimal_places')
                    ->label('Digit desimal')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(6)
                    ->default(2)
                    ->required(),
            ])
            ->createOptionAction(
                fn (Action $action): Action => self::secureCreateAction($action, 'Buat satuan baru'),
            )
            ->createOptionUsing(static function (array $data): int {
                self::authorizeManage();
                MasterDataDuplicateGuard::assertNoExactName(Unit::class, (string) $data['name']);

                return Unit::query()->create([
                    'code' => Str::upper(trim((string) $data['code'])),
                    'name' => trim((string) $data['name']),
                    'symbol' => trim((string) $data['symbol']),
                    'decimal_places' => (int) $data['decimal_places'],
                    'is_active' => true,
                ])->getKey();
            });
    }

    public static function product(Select $select): Select
    {
        return $select
            ->createOptionForm([
                self::category(
                    Select::make('category_id')
                        ->label('Kategori')
                        ->options(static fn (): array => ProductCategory::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                ),
                self::unit(
                    Select::make('default_unit_id')
                        ->label('Satuan default')
                        ->options(static fn (): array => Unit::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                ),
                TextInput::make('code')
                    ->label('Kode produk')
                    ->required()
                    ->maxLength(50)
                    ->unique(table: Product::class, column: 'code'),
                TextInput::make('name')
                    ->label('Nama produk')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->helperText(static fn (?string $state): ?string => MasterDataDuplicateGuard::hint(Product::class, $state)),
                Textarea::make('description')
                    ->label('Deskripsi / spesifikasi umum')
                    ->rows(3),
            ])
            ->createOptionAction(
                fn (Action $action): Action => self::secureCreateAction($action, 'Buat produk baru'),
            )
            ->createOptionUsing(static function (array $data): int {
                self::authorizeManage();
                MasterDataDuplicateGuard::assertNoExactName(Product::class, (string) $data['name']);

                return Product::query()->create([
                    'category_id' => $data['category_id'],
                    'default_unit_id' => $data['default_unit_id'],
                    'code' => Str::upper(trim((string) $data['code'])),
                    'name' => trim((string) $data['name']),
                    'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                    'is_active' => true,
                ])->getKey();
            });
    }

    private static function secureCreateAction(Action $action, string $label): Action
    {
        return $action
            ->tooltip($label)
            ->modalHeading($label)
            ->modalSubmitActionLabel('Buat')
            ->visible(static fn (): bool => self::canManage());
    }

    private static function authorizeManage(): void
    {
        abort_unless(self::canManage(), 403, 'Anda tidak memiliki izin untuk menambah data master.');
    }
}
