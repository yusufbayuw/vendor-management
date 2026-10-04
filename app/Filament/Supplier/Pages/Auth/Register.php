<?php

namespace App\Filament\Supplier\Pages\Auth;

use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemRole;
use App\Enums\VerificationStatus;
use App\Models\Bank;
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
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

class Register extends BaseRegister
{
    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Buat akun')
                ->description('Isi data yang bertanda * untuk membuat akun. Data usaha lainnya boleh dilengkapi setelah masuk.')
                ->schema([
                    $this->getNameFormComponent()
                        ->label('Nama lengkap penanggung jawab')
                        ->helperText('Isi nama orang yang bisa dihubungi oleh SPPG.'),
                    TextInput::make('supplier_phone')
                        ->label('Nomor WhatsApp / HP aktif')
                        ->helperText('Gunakan nomor yang aktif. Nomor ini juga bisa dipakai untuk masuk ke portal.')
                        ->tel()
                        ->required()
                        ->maxLength(40)
                        ->rule(static function (): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail): void {
                                $phone = LoginIdentifier::normalizePhone((string) $value);

                                if ($phone !== null && User::query()->where('phone', $phone)->exists()) {
                                    $fail('Nomor WhatsApp / HP ini sudah digunakan oleh akun lain.');
                                }
                            };
                        }),
                    TextInput::make('email')
                        ->label('Email (jika ada)')
                        ->helperText('Tidak wajib. Jika diisi, email dapat dipakai untuk menerima pemberitahuan.')
                        ->email()
                        ->maxLength(255)
                        ->unique(User::class, 'email'),
                    TextInput::make('legal_name')
                        ->label('Nama usaha / nama resmi')
                        ->helperText('Jika punya NIB/NPWP, isi sesuai dokumen. Jika belum punya, isi nama usaha atau toko yang biasa digunakan.')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('display_name')
                        ->label('Nama toko / nama yang dikenal (jika berbeda)')
                        ->helperText('Opsional. Contoh: Berkah Sayur Bandung. Jika kosong, nama usaha di atas akan digunakan.')
                        ->maxLength(255),
                    Select::make('supplier_type')
                        ->label('Bentuk usaha')
                        ->helperText('Pilih yang paling sesuai dengan kondisi usaha Anda.')
                        ->options([
                            'individual' => 'Usaha perorangan / UMKM',
                            'company' => 'Perusahaan / badan usaha',
                            'cooperative' => 'Koperasi',
                            'other' => 'Lainnya',
                        ])
                        ->placeholder('Pilih bentuk usaha')
                        ->live()
                        ->required(),
                    $this->getPasswordFormComponent()
                        ->label('Buat kata sandi'),
                    $this->getPasswordConfirmationFormComponent()
                        ->label('Ulangi kata sandi'),
                ])
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ]),
            Section::make('Data usaha (boleh dilengkapi nanti)')
                ->description('Jika data ini belum siap, Anda boleh melewatinya dan melengkapinya setelah masuk.')
                ->schema([
                    TextInput::make('npwp')
                        ->label('Nomor NPWP (jika ada)')
                        ->helperText('Isi angka NPWP usaha atau pemilik usaha bila digunakan untuk usaha.')
                        ->maxLength(40),
                    Toggle::make('npwp_not_applicable')
                        ->label('Saya belum memiliki NPWP')
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set): void {
                            if ($state) {
                                $set('npwp', null);
                            }
                        }),
                    TextInput::make('nib')
                        ->label('Nomor Induk Berusaha / NIB (jika ada)')
                        ->helperText('NIB biasanya terdapat pada dokumen OSS.')
                        ->maxLength(80),
                    Toggle::make('nib_not_applicable')
                        ->label('Saya belum memiliki NIB')
                        ->visible(fn (Get $get): bool => in_array($get('supplier_type'), ['company', 'cooperative'], true))
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set): void {
                            if ($state) {
                                $set('nib', null);
                            }
                        }),
                    Textarea::make('address')
                        ->label('Alamat lengkap usaha')
                        ->helperText('Tulis nama jalan, nomor, RT/RW, atau patokan bila diperlukan.')
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
                        ->label('Kode pos (jika tahu)')
                        ->maxLength(10),
                ])
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->collapsible()
                ->collapsed(),
            Section::make('Produk & dokumen (boleh dilengkapi nanti)')
                ->description('Pilih produk yang bisa dipasok dan tambahkan dokumen yang sudah tersedia. Anda bisa menambahkan beberapa dokumen sekaligus.')
                ->schema([
                    Select::make('product_ids')
                        ->label('Apa yang bisa Anda pasok?')
                        ->helperText('Boleh pilih lebih dari satu. Ketik nama produk untuk mencari.')
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
                        ->columnSpanFull(),
                    Repeater::make('legal_documents')
                        ->label('Dokumen pendukung usaha')
                        ->helperText('Contoh: NIB, NPWP, sertifikat halal, izin usaha, atau dokumen lain. Tambahkan sebanyak yang sudah Anda punya.')
                        ->schema([
                            Select::make('document_type')
                                ->label('Dokumen apa ini?')
                                ->options([
                                    'nib' => 'NIB',
                                    'npwp' => 'NPWP',
                                    'halal_certificate' => 'Sertifikat Halal',
                                    'business_license' => 'Izin Usaha',
                                    'food_safety' => 'Sertifikat Keamanan Pangan',
                                    'domicile' => 'Surat Domisili',
                                    'other' => 'Dokumen lainnya',
                                ])
                                ->placeholder('Pilih jenis dokumen')
                                ->required(),
                            TextInput::make('document_number')
                                ->label('Nomor dokumen (jika ada)')
                                ->maxLength(255),
                            FileUpload::make('file_path')
                                ->label('Pilih file')
                                ->helperText('PDF, JPG, PNG, atau WebP. Maksimal 10 MB per file.')
                                ->disk(VendorFileStorage::DISK)
                                ->directory('supplier-documents')
                                ->visibility('private')
                                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(10240)
                                ->required()
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                        ])
                        ->defaultItems(0)
                        ->addActionLabel('Tambah dokumen lain')
                        ->reorderable(false)
                        ->columnSpanFull(),
                ])
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->collapsible()
                ->collapsed(),
            Section::make('Rekening untuk pembayaran (boleh dilengkapi nanti)')
                ->description('Pilih rekening yang akan menerima pembayaran dari SPPG. Kode bank akan diisi otomatis.')
                ->schema([
                    Select::make('bank_id')
                        ->label('Pilih bank')
                        ->placeholder('Ketik atau pilih nama bank')
                        ->helperText('Tidak perlu mengisi kode bank secara manual.')
                        ->options(fn (): array => DatabaseSchema::hasTable('banks') ? Bank::activeOptions() : [])
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => filled($get('bank_account_number')) || filled($get('bank_account_holder'))),
                    TextInput::make('bank_account_number')
                        ->label('Nomor rekening')
                        ->helperText('Isi hanya angka nomor rekening.')
                        ->maxLength(100)
                        ->required(fn (Get $get): bool => filled($get('bank_id')) || filled($get('bank_account_holder'))),
                    TextInput::make('bank_account_holder')
                        ->label('Nama pemilik rekening')
                        ->helperText('Isi persis seperti nama yang tercatat di bank.')
                        ->maxLength(255)
                        ->required(fn (Get $get): bool => filled($get('bank_id')) || filled($get('bank_account_number'))),
                ])
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->collapsible()
                ->collapsed(),
        ]);
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

            foreach ($data['legal_documents'] ?? [] as $document) {
                if (! filled($document['file_path'] ?? null)) {
                    continue;
                }

                SupplierDocument::query()->create([
                    'supplier_id' => $supplier->getKey(),
                    'document_type' => $document['document_type'],
                    'document_number' => ($document['document_number'] ?? null) ?: null,
                    'file_path' => $document['file_path'],
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
                filled($data['bank_id'] ?? null)
                && filled($data['bank_account_number'] ?? null)
                && filled($data['bank_account_holder'] ?? null)
            ) {
                $bank = Bank::query()
                    ->active()
                    ->findOrFail((int) $data['bank_id']);

                SupplierBankAccount::query()->create([
                    'supplier_id' => $supplier->getKey(),
                    'bank_id' => $bank->getKey(),
                    'bank_code' => $bank->code,
                    'bank_name' => $bank->name,
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

        if (filled($user->email) && ! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
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
