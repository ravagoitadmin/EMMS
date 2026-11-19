<?php

namespace Tests\Feature;

use App\Support\Activity\ActivityLabels;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Mockery;
use Tests\TestCase;

/**
 * Times on screen read 05:12:00 PM, never 17:12:00.
 */
class TimeFormatTest extends TestCase
{
    public function test_tables_show_a_date_and_time_in_twelve_hours_with_am_or_pm(): void
    {
        $table = Table::make(Mockery::mock(HasTable::class));

        $this->assertSame('Nov 14, 2026 05:12:00 PM', now()->setDateTime(2026, 11, 14, 17, 12, 0)->translatedFormat($table->getDefaultDateTimeDisplayFormat()));
        $this->assertSame('05:12:00 PM', now()->setDateTime(2026, 11, 14, 17, 12, 0)->translatedFormat($table->getDefaultTimeDisplayFormat()));
        $this->assertSame('01:02:00 AM', now()->setDateTime(2026, 11, 14, 1, 2, 0)->translatedFormat($table->getDefaultTimeDisplayFormat()));
    }

    public function test_view_pages_use_the_same_format(): void
    {
        $schema = Schema::make();

        $this->assertSame('Nov 14, 2026 05:12:00 PM', now()->setDateTime(2026, 11, 14, 17, 12, 0)->translatedFormat($schema->getDefaultDateTimeDisplayFormat()));
        $this->assertSame('05:12:00 PM', now()->setDateTime(2026, 11, 14, 17, 12, 0)->translatedFormat($schema->getDefaultTimeDisplayFormat()));
    }

    public function test_pickers_display_twelve_hours_with_am_or_pm(): void
    {
        $this->assertSame('M j, Y h:i A', DateTimePicker::make('at')->seconds(false)->getDisplayFormat());
        $this->assertSame('M j, Y h:i:s A', DateTimePicker::make('at')->seconds()->getDisplayFormat());
        $this->assertSame('h:i A', TimePicker::make('at')->seconds(false)->getDisplayFormat());
        $this->assertSame('h:i:s A', TimePicker::make('at')->seconds()->getDisplayFormat());
    }

    public function test_the_activity_log_shows_stored_times_the_same_way(): void
    {
        $this->assertSame('Nov 10, 2026 05:30:00 PM', ActivityLabels::formatValue('2026-11-10 17:30:00', 'time_in'));
        $this->assertSame('Nov 10, 2026 12:05:09 AM', ActivityLabels::formatValue('2026-11-10 00:05:09', 'time_out'));
        $this->assertSame('2026-11-10', ActivityLabels::formatValue('2026-11-10', 'due_date'));
        $this->assertSame('2026-13-45 99:99:99', ActivityLabels::formatValue('2026-13-45 99:99:99', 'x'));
    }
}
