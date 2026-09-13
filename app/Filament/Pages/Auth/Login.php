<?php

namespace App\Filament\Pages\Auth;

use App\Support\Auth\LoginIdentifier;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('login')
                ->label('Email / Nomor HP / Username')
                ->required()
                ->autocomplete('username')
                ->autofocus(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return LoginIdentifier::credentials(
            (string) $data['login'],
            (string) $data['password'],
        );
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => 'Email, nomor HP, username, atau password tidak sesuai.',
        ]);
    }
}
