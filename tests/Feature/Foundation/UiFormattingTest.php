<?php

namespace Tests\Feature\Foundation;

use App\Support\UiNumber;
use Illuminate\Support\Number;
use Tests\TestCase;

class UiFormattingTest extends TestCase
{
    public function test_number_ui_format_is_separated_from_storage_value(): void
    {
        $this->assertSame('1.234.567', UiNumber::formatForInput(1234567, 'amount'));
        $this->assertSame('1.234,5', UiNumber::formatForInput(1234.5, 'requested_qty'));

        $this->assertSame('1234567', UiNumber::normalizeForStorage('1.234.567', 'amount'));
        $this->assertSame('1234.5', UiNumber::normalizeForStorage('1.234,5', 'requested_qty'));
    }

    public function test_number_locale_is_indonesian_for_ui_formatting(): void
    {
        $this->assertSame('id_ID', Number::defaultLocale());
        $this->assertStringContainsString('1.234', Number::format(1234));
    }
}
