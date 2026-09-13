<?php

namespace App\Filament\Supplier\Pages;

use App\Services\Auth\PhoneVerificationService;
use DomainException;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Throwable;

class VerifyPhone extends Page implements HasForms
{
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'verify-phone';

    protected static ?string $title = 'Verifikasi Nomor HP';

    protected string $view = 'filament.supplier.pages.verify-phone';

    public ?array $data = [];

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user !== null, 403);

        if (config('phone-verification.mode', 'manual') !== 'otp' || $user->hasVerifiedPhone()) {
            $this->redirect('/supplier');

            return;
        }

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('code')
                    ->label('Kode OTP')
                    ->helperText('Masukkan 6 digit kode yang dikirim ke nomor HP Anda.')
                    ->required()
                    ->numeric()
                    ->length(6)
                    ->autocomplete('one-time-code')
                    ->extraInputAttributes(['inputmode' => 'numeric']),
            ]);
    }

    public function sendCode(): void
    {
        if (config('phone-verification.mode', 'manual') !== 'otp') {
            Notification::make()
                ->warning()
                ->title('Verifikasi nomor HP dilakukan oleh admin.')
                ->send();

            return;
        }

        $user = auth()->user();

        if (! $user || blank($user->phone)) {
            Notification::make()
                ->danger()
                ->title('Nomor HP belum tersedia.')
                ->body('Tambahkan nomor HP melalui Profil terlebih dahulu.')
                ->send();

            return;
        }

        try {
            app(PhoneVerificationService::class)->send($user, request()->ip());

            Notification::make()
                ->success()
                ->title('OTP berhasil dikirim.')
                ->body('Kode berlaku selama 5 menit.')
                ->send();
        } catch (DomainException $exception) {
            Notification::make()->warning()->title($exception->getMessage())->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()
                ->danger()
                ->title('OTP gagal dikirim.')
                ->body('Silakan coba kembali beberapa saat lagi.')
                ->send();
        }
    }

    public function verify(): void
    {
        if (config('phone-verification.mode', 'manual') !== 'otp') {
            Notification::make()
                ->warning()
                ->title('Verifikasi nomor HP dilakukan oleh admin.')
                ->send();

            return;
        }

        $state = $this->form->getState();
        $user = auth()->user();

        abort_unless($user !== null, 403);

        try {
            app(PhoneVerificationService::class)->verify($user, (string) $state['code']);

            Notification::make()
                ->success()
                ->title('Nomor HP berhasil diverifikasi.')
                ->send();

            $this->redirect('/supplier');
        } catch (DomainException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    public function maskedPhone(): string
    {
        $phone = (string) auth()->user()?->phone;

        if ($phone === '') {
            return 'Belum diisi';
        }

        if (strlen($phone) <= 7) {
            return $phone;
        }

        return '+'.substr($phone, 0, 4).' **** '.substr($phone, -4);
    }

    public function usesLogDriver(): bool
    {
        return config('phone-verification.driver') === 'log';
    }
}
