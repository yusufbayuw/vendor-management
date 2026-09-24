<?php

namespace App\Services\License;

use App\Support\SystemBoot;

class LicenseService extends SystemBoot
{
    public function checkLicense(): bool
    {
        return $this->v();
    }

    public function getSystemSignature(): string
    {
        return $this->sig();
    }

    public function verifyLicense(?string $key): bool
    {
        return $this->p($key);
    }

    public function activate(string $key): bool
    {
        return $this->store($key);
    }

    public function getLicenseDetails(): ?array
    {
        return $this->meta();
    }
}
