<?php

namespace App\Rules;

use App\Support\Auth\LoginCaptcha;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class LoginCaptchaRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! LoginCaptcha::validate(is_string($value) ? $value : null)) {
            $fail('Kode keamanan tidak sesuai atau sudah kedaluwarsa. Silakan coba lagi.');
        }
    }
}
