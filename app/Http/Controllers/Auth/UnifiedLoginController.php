<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\LoginCaptchaRule;
use App\Services\PortalDestinationService;
use App\Support\Auth\LoginCaptcha;
use App\Support\Auth\LoginIdentifier;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UnifiedLoginController extends Controller
{
    public function create(PortalDestinationService $destinations): View|RedirectResponse
    {
        if (Auth::check()) {
            $user = Auth::user();

            if ($user instanceof User && ($destination = $destinations->pathFor($user))) {
                return redirect($destination);
            }

            Auth::logout();
        }

        return view('auth.unified-login', [
            'captcha' => LoginCaptcha::ensure(),
        ]);
    }

    public function captcha(): JsonResponse
    {
        $challenge = LoginCaptcha::refresh();

        return response()
            ->json([
                'light' => $challenge['image_light'],
                'dark' => $challenge['image_dark'],
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function store(
        Request $request,
        PortalDestinationService $destinations,
    ): RedirectResponse {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'captcha' => ['required', new LoginCaptchaRule],
        ], attributes: [
            'login' => 'email, nomor HP, atau username',
            'captcha' => 'kode keamanan',
        ]);

        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            event(new Lockout($request));

            $seconds = RateLimiter::availableIn($throttleKey);
            LoginCaptcha::refresh();

            throw ValidationException::withMessages([
                'login' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        $credentials = LoginIdentifier::credentials(
            (string) $data['login'],
            (string) $data['password'],
        );
        $credentials['is_active'] = true;

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey);
            LoginCaptcha::refresh();

            throw ValidationException::withMessages([
                'login' => 'Email, nomor HP, username, atau password tidak sesuai.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $user = Auth::user();

        if (! $user instanceof User || ! ($destination = $destinations->pathFor($user))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            LoginCaptcha::refresh();

            throw ValidationException::withMessages([
                'login' => 'Akun tidak memiliki akses ke portal.',
            ]);
        }

        $request->session()->regenerate();

        $intended = $request->session()->get('url.intended');

        if (! $destinations->intendedUrlIsAllowed(
            $user,
            is_string($intended) ? $intended : null,
        )) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended($destination);
    }

    private function throttleKey(Request $request): string
    {
        return Str::transliterate(
            Str::lower(trim((string) $request->input('login'))).'|'.$request->ip(),
        );
    }
}
