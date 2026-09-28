<?php

namespace Tests;

use App\Http\Middleware\EnsureLicenseIsValid;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected bool $bypassLicenseMiddleware = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->bypassLicenseMiddleware) {
            $this->withoutMiddleware(EnsureLicenseIsValid::class);
        }
    }
}
