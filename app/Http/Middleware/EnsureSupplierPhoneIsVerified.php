<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupplierPhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('phone-verification.mode', 'manual') !== 'otp') {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || $user->hasVerifiedPhone()) {
            return $next($request);
        }

        if ($request->is('supplier/verify-phone') || $request->is('supplier/profile') || $request->is('supplier/logout')) {
            return $next($request);
        }

        return redirect('/supplier/verify-phone');
    }
}
