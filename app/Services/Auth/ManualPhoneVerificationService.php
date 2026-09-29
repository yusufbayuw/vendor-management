<?php

namespace App\Services\Auth;

use App\Models\PhoneVerificationRequest;
use App\Models\User;
use App\Support\Auth\LoginIdentifier;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManualPhoneVerificationService
{
    public function currentOrCreate(User $user): PhoneVerificationRequest
    {
        return DB::transaction(function () use ($user): PhoneVerificationRequest {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (blank($lockedUser->phone)) {
                throw new DomainException('Nomor HP belum tersedia pada akun.');
            }

            if ($lockedUser->hasVerifiedPhone()) {
                throw new DomainException('Nomor HP sudah terverifikasi.');
            }

            $ttlHours = max(1, (int) config('phone-verification.manual_request_ttl_hours', 168));
            $cutoff = now()->subHours($ttlHours);

            $pending = PhoneVerificationRequest::query()
                ->where('user_id', $lockedUser->getKey())
                ->where('phone', $lockedUser->phone)
                ->where('method', PhoneVerificationRequest::METHOD_WHATSAPP_MANUAL)
                ->where('status', PhoneVerificationRequest::STATUS_PENDING)
                ->where('requested_at', '>=', $cutoff)
                ->latest('requested_at')
                ->first();

            if ($pending) {
                return $pending;
            }

            PhoneVerificationRequest::query()
                ->where('user_id', $lockedUser->getKey())
                ->where('status', PhoneVerificationRequest::STATUS_PENDING)
                ->update([
                    'status' => PhoneVerificationRequest::STATUS_EXPIRED,
                    'reviewed_at' => now(),
                ]);

            return PhoneVerificationRequest::query()->create([
                'user_id' => $lockedUser->getKey(),
                'phone' => $lockedUser->phone,
                'method' => PhoneVerificationRequest::METHOD_WHATSAPP_MANUAL,
                'reference' => $this->uniqueReference(),
                'status' => PhoneVerificationRequest::STATUS_PENDING,
                'requested_at' => now(),
                'metadata' => [
                    'supplier_id' => $lockedUser->suppliers()
                        ->wherePivot('is_active', true)
                        ->orderByPivot('is_owner', 'desc')
                        ->value('suppliers.id'),
                ],
            ]);
        });
    }

    public function whatsappUrl(User $user, PhoneVerificationRequest $request): string
    {
        $target = LoginIdentifier::normalizePhone((string) config('phone-verification.whatsapp_admin'));

        if (blank($target)) {
            throw new DomainException('Nomor WhatsApp admin belum dikonfigurasi.');
        }

        if ($request->user_id !== $user->getKey() || $request->phone !== $user->phone) {
            throw new DomainException('Permintaan verifikasi tidak cocok dengan nomor HP akun saat ini.');
        }

        $supplier = $user->suppliers()
            ->wherePivot('is_active', true)
            ->orderByPivot('is_owner', 'desc')
            ->orderBy('suppliers.id')
            ->first();

        $supplierName = $supplier?->display_name ?: $supplier?->legal_name ?: '-';

        $message = implode("\n", [
            'Halo Admin SPPG, saya ingin memverifikasi nomor WhatsApp untuk akun supplier.',
            '',
            'Supplier: '.$supplierName,
            'PIC: '.$user->name,
            'Username: '.($user->username ?: '-'),
            'Nomor terdaftar: +'.$user->phone,
            'Kode verifikasi: '.$request->reference,
            '',
            'Mohon verifikasi nomor ini pada Vendor Management.',
        ]);

        return 'https://wa.me/'.$target.'?text='.rawurlencode($message);
    }

    public function verify(PhoneVerificationRequest $request, User $actor): User
    {
        return DB::transaction(function () use ($request, $actor): User {
            $lockedRequest = PhoneVerificationRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($lockedRequest->status !== PhoneVerificationRequest::STATUS_PENDING) {
                throw new DomainException('Permintaan verifikasi WhatsApp sudah diproses.');
            }

            $user = User::query()->lockForUpdate()->findOrFail($lockedRequest->user_id);

            if (blank($user->phone) || $user->phone !== $lockedRequest->phone) {
                throw new DomainException('Nomor HP akun sudah berubah. Buat permintaan verifikasi baru.');
            }

            $verifiedAt = now();

            $user->forceFill([
                'phone_verified_at' => $verifiedAt,
                'phone_verification_method' => PhoneVerificationRequest::METHOD_WHATSAPP_MANUAL,
                'phone_verified_by' => $actor->getKey(),
            ])->save();

            $lockedRequest->forceFill([
                'status' => PhoneVerificationRequest::STATUS_VERIFIED,
                'reviewed_at' => $verifiedAt,
                'reviewed_by' => $actor->getKey(),
                'rejection_reason' => null,
            ])->save();

            PhoneVerificationRequest::query()
                ->where('user_id', $user->getKey())
                ->whereKeyNot($lockedRequest->getKey())
                ->where('status', PhoneVerificationRequest::STATUS_PENDING)
                ->update([
                    'status' => PhoneVerificationRequest::STATUS_EXPIRED,
                    'reviewed_at' => $verifiedAt,
                    'reviewed_by' => $actor->getKey(),
                ]);

            return $user->refresh();
        });
    }

    public function reject(PhoneVerificationRequest $request, User $actor, string $reason): PhoneVerificationRequest
    {
        if (blank(trim($reason))) {
            throw new DomainException('Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($request, $actor, $reason): PhoneVerificationRequest {
            $lockedRequest = PhoneVerificationRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($lockedRequest->status !== PhoneVerificationRequest::STATUS_PENDING) {
                throw new DomainException('Permintaan verifikasi WhatsApp sudah diproses.');
            }

            $lockedRequest->forceFill([
                'status' => PhoneVerificationRequest::STATUS_REJECTED,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->getKey(),
                'rejection_reason' => trim($reason),
            ])->save();

            return $lockedRequest->refresh();
        });
    }

    private function uniqueReference(): string
    {
        do {
            $reference = 'WA-'.Str::upper(Str::random(8));
        } while (PhoneVerificationRequest::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
