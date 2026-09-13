<?php

namespace App\Filament\Pages\Auth;

use App\Rules\LoginCaptchaRule;
use App\Support\Auth\LoginCaptcha;
use App\Support\Auth\LoginIdentifier;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class Login extends BaseLogin
{
    public function mount(): void
    {
        parent::mount();

        LoginCaptcha::refresh();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('login')
                ->label('Email / Nomor HP / Username')
                ->required()
                ->autocomplete('username')
                ->autofocus(),
            $this->getPasswordFormComponent(),
            View::make('filament.auth.login-captcha')
                ->viewData(fn (): array => [
                    'lightCaptchaImage' => LoginCaptcha::lightImageDataUri(),
                    'darkCaptchaImage' => LoginCaptcha::darkImageDataUri(),
                ]),
            TextInput::make('captcha')
                ->label('Masukkan kode keamanan')
                ->helperText('Masukkan 5 karakter pada gambar. Huruf besar/kecil tidak dibedakan.')
                ->required()
                ->autocomplete('off')
                ->maxLength(5)
                ->rule(new LoginCaptchaRule),
            $this->getRememberFormComponent(),
        ]);
    }

    public function refreshCaptcha(): void
    {
        LoginCaptcha::refresh();
        $this->data['captcha'] = null;
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
        LoginCaptcha::refresh();
        $this->data['captcha'] = null;

        throw ValidationException::withMessages([
            'data.login' => 'Email, nomor HP, username, atau password tidak sesuai.',
        ]);
    }

    protected function onValidationError(ValidationException $exception): void
    {
        LoginCaptcha::refresh();
        $this->data['captcha'] = null;

        parent::onValidationError($exception);
    }
}
