<?php

namespace App\Filament\Supplier\Pages\Auth;

use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemRole;
use App\Enums\VerificationStatus;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierDocument;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Auth\PhoneVerificationService;
use App\Services\Files\VendorFileStorage;
use App\Services\Regions\IndonesiaRegionService;
use App\Support\Auth\LoginIdentifier;
use Closure;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent()->label('Nama PIC'),

            TextInput::make('email')
                ->label('Email PIC')
                ->helperText('Opsional. Login tetap dapat menggunakan nomor HP atau username yang dibuat otomatis.')
                ->email()
                ->maxLength(255)
                ->unique(User::class, 'email'),

            TextInput::make('supplier_phone')
                ->label('Nomor HP PIC / Supplier')
                ->helperText('Nomor ini juga dapat digunakan untuk login.')
                ->tel()
                ->required()
                ->maxLength(40)
                ->rule(static function (): Closure {
                    return static function (string $attribute, mixed $value, Closure $fail): void {
                        $phone = LoginIdentifier::normalizePhone((string) $value);

                        if ($phone !== null && User::query()->where('phone', $phone)->exists()) {
                            $fail('Nomor HP sudah digunakan oleh akun lain.');
                        }
                    };
                }),

            TextInput::make('legal_name')
                ->label('Nama legal supplier')
                ->required()
                ->maxLength(255),

            TextInput::make('display_name')
                ->label('Nama dagang')
                ->helperText('Jika dikosongkan, sistem menggunakan nama legal.')
                ->maxLength(255),

            Select::make('supplier_type')
                ->label('Jenis supplier')
                ->options([
                    'company' => 'Perusahaan',
                    'individual' => 'Perorangan',
                    'cooperative' => 'Koperasi',
                    'other' => 'Lainnya',
                ])
                ->default('company')
                ->live()
                ->required(),

            TextInput::make('npwp')
                ->label('NPWP')
                ->maxLength(40),

            Toggle::make('npwp_not_applicable')
                ->label('Tidak memiliki NPWP / tidak berlaku')
                ->helperText('Pilih hanya jika kondisi ini memang benar. Deklarasi disimpan sebagai bagian kelengkapan profil.')
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    if ($state) {
                        $set('npwp', null);
                    }
                }),

            TextInput::make('nib')
                ->label('NIB')
                ->maxLength(80),

            Toggle::make('nib_not_applicable')
                ->label('NIB tidak dimiliki / tidak berlaku')
                ->helperText('Untuk badan usaha formal, NIB atau deklarasi ini akan diperiksa saat onboarding.')
                ->visible(fn (Get $get): bool => in_array($get('supplier_type'), ['company', 'cooperative'], true))
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    if ($state) {
                        $set('nib', null);
                    }
                }),

            Textarea::make('address')
                ->label('Alamat lengkap')
                ->rows(3)
                ->columnSpanFull(),

            Select::make('province_code')
                ->label('Provinsi')
                ->placeholder('Pilih provinsi')
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
                ->placeholder('Pilih kabupaten / kota')
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
                ->placeholder('Pilih kecamatan')
                ->options(fn (Get $get): array => app(IndonesiaRegionService::class)->districts($get('regency_code')))
                ->searchable()
                ->live()
                ->disabled(fn (Get $get): bool => blank($get('regency_code')))
                ->afterStateUpdated(fn (Set $set) => $set('village_code', null)),

            Select::make('village_code')
                ->label('Desa / Kelurahan')
                ->placeholder('Pilih desa / kelurahan')
                ->options(fn (Get $get): array => app(IndonesiaRegionService::class)->villages($get('district_code')))
                ->searchable()
                ->disabled(fn (Get $get): bool => blank($get('district_code'))),

            TextInput::make('postal_code')
                ->label('Kode Pos')
                ->maxLength(10),

            Select::make('product_ids')
                ->label('Komoditas / produk yang dapat dipasok')
                ->helperText('Pilih dari master produk yang sudah tersedia. Data ini langsung menjadi katalog supplier.')
                ->options(fn (): array => DatabaseSchema::hasTable('products')
                    ? Product::query()
                        ->with('category')
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(static fn (Product $product): array => [
                            $product->getKey() => ($product->category?->name ? $product->category->name.' — ' : '').$product->name,
                        ])
                        ->all()
                    : [])
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Boleh dilengkapi setelah login. Pilihan yang diisi saat registrasi langsung menjadi katalog supplier.')
                ->columnSpanFull(),

            Select::make('legal_document_type')
                ->label('Jenis dokumen legal pendukung')
                ->options([
                    'nib' => 'NIB',
                    'npwp' => 'NPWP',
                    'halal_certificate' => 'Sertifikat Halal',
                    'business_license' => 'Izin Usaha',
                    'food_safety' => 'Sertifikat Keamanan Pangan',
                    'domicile' => 'Surat Domisili',
                    'other' => 'Dokumen legal lainnya',
                ])
                ->default('nib')
                ->required(),

            TextInput::make('legal_document_number')
                ->label('Nomor dokumen legal')
                ->maxLength(255),

            FileUpload::make('legal_document_file')
                ->label('Upload dokumen legal')
                ->helperText('Boleh dilengkapi setelah login. Minimal satu dokumen legal wajib tersedia sebelum pengajuan verifikasi. PDF/JPG/PNG/WebP, maksimum 10 MB.')
                ->disk(VendorFileStorage::DISK)
                ->directory('supplier-documents')
                ->visibility('private')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(10240)
                ->columnSpanFull(),

            TextInput::make('bank_name')
                ->label('Nama bank')
                ->helperText('Boleh dilengkapi setelah login. Jika salah satu data rekening diisi, seluruh data rekening wajib lengkap.')
                ->maxLength(255)
                ->required(fn (Get $get): bool => filled($get('bank_account_number')) || filled($get('bank_account_holder'))),

            TextInput::make('bank_account_number')
                ->label('Nomor rekening')
                ->maxLength(100)
                ->required(fn (Get $get): bool => filled($get('bank_name')) || filled($get('bank_account_holder'))),

            TextInput::make('bank_account_holder')
                ->label('Nama pemilik rekening')
                ->maxLength(255)
                ->required(fn (Get $get): bool => filled($get('bank_name')) || filled($get('bank_account_number'))),

            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ])->columns(2);
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $user = DB::transaction(function () use ($data): User {
            $email = filled($data['email'] ?? null) ? (string) $data['email'] : null;
            $phone = LoginIdentifier::normalizePhone((string) $data['supplier_phone']);

            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $this->generateUsername($data, $phone),
                'email' => $email,
                'phone' => $phone,
                'password' => $data['password'],
            ]);

            $user->assignRole(SystemRole::SupplierAdmin->value);

            $supplier = Supplier::query()->create([
                'code' => $this->generateSupplierCode(),
                'legal_name' => $data['legal_name'],
                'display_name' => ($data['display_name'] ?? null) ?: $data['legal_name'],
                'supplier_type' => $data['supplier_type'],
                'npwp' => ($data['npwp'] ?? null) ?: null,
                'nib' => ($data['nib'] ?? null) ?: null,
                'email' => $email,
                'phone' => $phone,
                'address' => ($data['address'] ?? null) ?: null,
                'province_code' => ($data['province_code'] ?? null) ?: null,
                'regency_code' => ($data['regency_code'] ?? null) ?: null,
                'district_code' => ($data['district_code'] ?? null) ?: null,
                'village_code' => ($data['village_code'] ?? null) ?: null,
                'postal_code' => ($data['postal_code'] ?? null) ?: null,
                'onboarding_exemptions' => array_filter([
                    'npwp' => (bool) ($data['npwp_not_applicable'] ?? false),
                    'nib' => (bool) ($data['nib_not_applicable'] ?? false),
                ]),
                'status' => SupplierStatus::Draft,
            ]);

            $supplier->users()->attach($user->getKey(), [
                'is_owner' => true,
                'is_active' => true,
            ]);

            if (filled($data['legal_document_file'] ?? null)) {
                SupplierDocument::query()->create([
                    'supplier_id' => $supplier->getKey(),
                    'document_type' => $data['legal_document_type'],
                    'document_number' => ($data['legal_document_number'] ?? null) ?: null,
                    'file_path' => $data['legal_document_file'],
                    'status' => SupplierDocumentStatus::Uploaded,
                ]);
            }

            foreach (array_unique(array_map('intval', $data['product_ids'] ?? [])) as $productId) {
                SupplierProduct::query()->create([
                    'supplier_id' => $supplier->getKey(),
                    'product_id' => $productId,
                    'lead_time_days' => 0,
                    'is_available' => true,
                ]);
            }

            if (
                filled($data['bank_name'] ?? null)
                && filled($data['bank_account_number'] ?? null)
                && filled($data['bank_account_holder'] ?? null)
            ) {
                SupplierBankAccount::query()->create([
                    'supplier_id' => $supplier->getKey(),
                    'bank_name' => $data['bank_name'],
                    'account_number' => $data['bank_account_number'],
                    'account_holder' => $data['bank_account_holder'],
                    'is_primary' => true,
                    'verification_status' => VerificationStatus::Pending,
                ]);
            }

            return $user;
        });

        if (config('phone-verification.mode', 'otp') === 'otp') {
            try {
                app(PhoneVerificationService::class)->send($user, request()->ip());
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $user;
    }

    private function generateUsername(array $data, ?string $phone): string
    {
        $sourceName = (string) (($data['display_name'] ?? null) ?: $data['legal_name']);
        $slug = Str::slug($sourceName, '.');

        if (blank($slug)) {
            $slug = 'supplier';
        }

        $phoneSuffix = filled($phone)
            ? substr((string) $phone, -4)
            : substr(hash('sha256', Str::lower($sourceName.'|'.($data['email'] ?? ''))), 0, 4);

        $candidate = Str::limit($slug, 45, '').'.'.$phoneSuffix;

        if (! User::query()->where('username', $candidate)->exists()) {
            return $candidate;
        }

        $seed = Str::lower($sourceName.'|'.$phone.'|'.($data['email'] ?? ''));

        foreach ([6, 8, 10, 12] as $hashLength) {
            $hash = substr(hash('sha256', $seed), 0, $hashLength);
            $maxSlugLength = max(8, 50 - strlen($phoneSuffix) - $hashLength - 2);
            $candidate = Str::limit($slug, $maxSlugLength, '').'.'.$phoneSuffix.'.'.$hash;

            if (! User::query()->where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        return substr(hash('sha256', $seed), 0, 50);
    }

    private function generateSupplierCode(): string
    {
        do {
            $code = 'SUP-'.Str::upper(Str::random(10));
        } while (Supplier::query()->where('code', $code)->exists());

        return $code;
    }
}
