<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\Auth\LoginIdentifier;
use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            TextInput::make('username')
                ->label('Username')
                ->required()
                ->maxLength(50)
                ->regex('/^[A-Za-z][A-Za-z0-9._-]{2,49}$/')
                ->unique(ignoreRecord: true)
                ->live(debounce: 500),
            TextInput::make('email')
                ->label('Email')
                ->helperText('Opsional jika akun menggunakan nomor HP atau username.')
                ->email()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->live(debounce: 500),
            TextInput::make('phone')
                ->label('Nomor HP')
                ->tel()
                ->maxLength(30)
                ->rule(function (): Closure {
                    return function (string $attribute, mixed $value, Closure $fail): void {
                        $phone = LoginIdentifier::normalizePhone(filled($value) ? (string) $value : null);

                        if ($phone === null) {
                            return;
                        }

                        if (User::query()->where('phone', $phone)->whereKeyNot($this->getUser()->getKey())->exists()) {
                            $fail('Nomor HP sudah digunakan oleh akun lain.');
                        }
                    };
                })
                ->dehydrateStateUsing(static fn (?string $state): ?string => LoginIdentifier::normalizePhone($state))
                ->live(debounce: 500),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('currentPassword')
            ->label('Password saat ini')
            ->password()
            ->autocomplete('current-password')
            ->currentPassword(guard: Filament::getAuthGuard())
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->visible(function (Get $get): bool {
                return filled($get('password'))
                    || ($get('email') !== $this->getUser()->getAttributeValue('email'))
                    || (strtolower((string) $get('username')) !== (string) $this->getUser()->getAttributeValue('username'))
                    || (LoginIdentifier::normalizePhone($get('phone')) !== $this->getUser()->getAttributeValue('phone'));
            })
            ->dehydrated(false);
    }
}
