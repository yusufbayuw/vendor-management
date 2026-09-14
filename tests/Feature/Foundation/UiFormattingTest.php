<?php

namespace Tests\Feature\Foundation;

use App\Support\UiNumber;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Infolist;
use Filament\Tables\Table;
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

    public function test_filament_uses_operational_date_time_and_number_defaults(): void
    {
        $this->assertSame('d/m/Y', Table::$defaultDateDisplayFormat);
        $this->assertSame('d/m/Y H:i', Table::$defaultDateTimeDisplayFormat);
        $this->assertSame('H:i', Table::$defaultTimeDisplayFormat);
        $this->assertSame('id_ID', Table::$defaultNumberLocale);

        $this->assertSame('d/m/Y', Infolist::$defaultDateDisplayFormat);
        $this->assertSame('d/m/Y H:i', Infolist::$defaultDateTimeDisplayFormat);
        $this->assertSame('H:i', Infolist::$defaultTimeDisplayFormat);
        $this->assertSame('id_ID', Infolist::$defaultNumberLocale);

        $this->assertSame('d/m/Y', DateTimePicker::$defaultDateDisplayFormat);
        $this->assertSame('d/m/Y H:i', DateTimePicker::$defaultDateTimeDisplayFormat);
        $this->assertSame('d/m/Y H:i:s', DateTimePicker::$defaultDateTimeWithSecondsDisplayFormat);
    }
}
