<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyPanelLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('GET')
            && ($request->is('admin/login') || $request->is('supplier/login'))
        ) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
