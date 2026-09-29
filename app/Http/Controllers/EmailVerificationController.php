<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __invoke(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->findOrFail($id);

        abort_unless(
            filled($user->email)
            && hash_equals((string) $hash, sha1($user->getEmailForVerification())),
            403,
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        if (auth()->id() === $user->getKey()) {
            return redirect('/supplier')->with('status', 'Email berhasil diverifikasi.');
        }

        return redirect('/supplier/login?email_verified=1');
    }
}
