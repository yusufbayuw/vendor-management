<?php

namespace Tests\Unit;

use App\Support\Auth\LoginIdentifier;
use PHPUnit\Framework\TestCase;

class LoginIdentifierTest extends TestCase
{
    public function test_email_is_resolved_case_insensitively(): void
    {
        $this->assertSame([
            'email' => 'vendor@example.com',
            'password' => 'secret',
        ], LoginIdentifier::credentials('Vendor@Example.COM', 'secret'));
    }

    public function test_username_is_resolved_case_insensitively(): void
    {
        $this->assertSame([
            'username' => 'vendor.bandung',
            'password' => 'secret',
        ], LoginIdentifier::credentials('Vendor.Bandung', 'secret'));
    }

    public function test_indonesian_phone_variants_are_normalized_to_same_value(): void
    {
        $expected = '6281234567890';

        $this->assertSame($expected, LoginIdentifier::normalizePhone('0812-3456-7890'));
        $this->assertSame($expected, LoginIdentifier::normalizePhone('+62 812 3456 7890'));
        $this->assertSame($expected, LoginIdentifier::normalizePhone('81234567890'));

        $this->assertSame([
            'phone' => $expected,
            'password' => 'secret',
        ], LoginIdentifier::credentials('0812 3456 7890', 'secret'));
    }
}
