<?php

namespace App\Http\Middleware;

use App\Services\License\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLicenseIsValid
{
    public function __construct(
        protected LicenseService $licenseService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('license/*')) {
            return $next($request);
        }

        if (! $this->licenseService->checkLicense()) {
            return redirect()->route('license.show');
        }

        return $next($request);
    }
}
