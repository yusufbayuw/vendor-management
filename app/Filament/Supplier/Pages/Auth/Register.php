<?php

namespace App\Filament\Supplier\Pages\Auth;

use App\Enums\SupplierStatus;
use App\Enums\SystemRole;
use App\Models\Supplier;
use App\Models\User;
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
            $this->getEmailFormComponent()->label('Email PIC'),
            TextInput::make('legal_name')->label('Nama legal supplier')->required()->maxLength(255),
            TextInput::make('display_name')->label('Nama dagang')->maxLength(255),
            Select::make('supplier_type')->label('Jenis supplier')->options([
                'company' => 'Perusahaan',
                'individual' => 'Perorangan',
                'cooperative' => 'Koperasi',
                'other' => 'Lainnya',
            ])->default('company')->required(),
            TextInput::make('supplier_phone')->label('Nomor telepon supplier')->tel()->required()->maxLength(40),
            TextInput::make('npwp')->label('NPWP')->maxLength(40),
            TextInput::make('nib')->label('NIB')->maxLength(80),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
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
                'email' => $data['email'],
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
