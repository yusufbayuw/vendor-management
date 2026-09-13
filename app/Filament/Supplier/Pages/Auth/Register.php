<?php

namespace App\Filament\Supplier\Pages\Auth;

use App\Enums\SupplierStatus;
use App\Enums\SystemRole;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Auth\LoginIdentifier;
use Closure;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent()->label('Nama PIC'),
            TextInput::make('username')
                ->label('Username')
                ->helperText('Minimal 3 karakter. Diawali huruf dan hanya boleh berisi huruf, angka, titik, garis bawah, atau strip.')
                ->required()
                ->maxLength(50)
                ->regex('/^[A-Za-z][A-Za-z0-9._-]{2,49}$/')
                ->unique(User::class, 'username'),
            TextInput::make('email')
                ->label('Email PIC')
                ->helperText('Opsional. Supplier tanpa email tetap dapat mendaftar menggunakan nomor HP dan username.')
                ->email()
                ->maxLength(255)
                ->unique(User::class, 'email'),
            TextInput::make('supplier_phone')
                ->label('Nomor HP PIC / Supplier')
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
            TextInput::make('legal_name')->label('Nama legal supplier')->required()->maxLength(255),
            TextInput::make('display_name')->label('Nama dagang')->maxLength(255),
            Select::make('supplier_type')->label('Jenis supplier')->options([
                'company' => 'Perusahaan',
                'individual' => 'Perorangan',
                'cooperative' => 'Koperasi',
                'other' => 'Lainnya',
            ])->default('company')->required(),
            TextInput::make('npwp')->label('NPWP')->maxLength(40),
            TextInput::make('nib')->label('NIB')->maxLength(80),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        return DB::transaction(function () use ($data): User {
            $email = filled($data['email'] ?? null) ? (string) $data['email'] : null;

            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $email,
                'phone' => $data['supplier_phone'],
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
                'phone' => $data['supplier_phone'],
                'status' => SupplierStatus::Draft,
            ]);

            $supplier->users()->attach($user->getKey(), [
                'is_owner' => true,
                'is_active' => true,
            ]);

            return $user;
        });
    }

    private function generateSupplierCode(): string
    {
        do {
            $code = 'SUP-'.Str::upper(Str::random(10));
        } while (Supplier::query()->where('code', $code)->exists());

        return $code;
    }
}
