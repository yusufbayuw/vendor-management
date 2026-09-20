<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupplierPhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('phone-verification.mode', 'otp');

        if ($mode === 'disabled' && app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        abort_unless($mode === 'otp', 503, 'Konfigurasi verifikasi nomor HP supplier tidak aman.');

        $user = $request->user();

        if (! $user || $user->hasOtpVerifiedPhone()) {
            return $next($request);
        }

        if ($request->is('supplier/verify-phone') || $request->is('supplier/profile') || $request->is('supplier/logout')) {
            return $next($request);
        }

        return redirect('/supplier/verify-phone');
    }
}
