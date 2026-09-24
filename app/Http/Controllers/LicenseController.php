<?php

namespace App\Http\Controllers;

use App\Support\SystemBoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenseController extends Controller
{
    public function __construct(
        protected SystemBoot $license,
    ) {}

    public function show(): View|RedirectResponse
    {
        if ($this->license->v()) {
            return redirect('/');
        }

        $configKey = config('app.license_key');

        if (is_string($configKey) && filled($configKey) && $this->license->store($configKey)) {
            return redirect('/')->with('success', 'Lisensi berhasil diaktifkan dari konfigurasi.');
        }

        return view('license.activate', [
            'signature' => $this->license->sig(),
        ]);
    }

    public function activate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'license_key' => ['required', 'string'],
        ]);

        if ($this->license->store($validated['license_key'])) {
            return redirect('/')->with('success', 'Lisensi berhasil diaktifkan.');
        }

        return back()
            ->withInput()
            ->with('error', 'Kunci lisensi tidak valid, kedaluwarsa, atau bukan untuk instalasi ini.');
    }

    public function status(): View
    {
        return view('license.status', [
            'details' => $this->license->meta(),
            'isValid' => $this->license->v(),
            'systemSignature' => $this->license->sig(),
        ]);
    }
}
