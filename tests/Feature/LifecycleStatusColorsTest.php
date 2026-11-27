<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LifecycleStatusColorsTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('statuses', function (Blueprint $table): void {
            $table->string('status_id')->primary();
            $table->string('status_title');
            $table->string('status_color')->default('gray');
            $table->string('status_icon');
        });

        Schema::create('actions', function (Blueprint $table): void {
            $table->string('a_id')->primary();
            $table->string('a_present_tense');
            $table->string('a_past_tense');
            $table->string('a_icon')->nullable();
        });

        // The rows as they are in production before the migration, plus a work order status that must not change.
        DB::table('statuses')->insert([
            ['status_id' => 'dep', 'status_title' => 'Deployed', 'status_color' => 'success', 'status_icon' => 'heroicon-o-check-circle'],
            ['status_id' => 'alc', 'status_title' => 'Allocated', 'status_color' => 'success', 'status_icon' => 'heroicon-o-check-circle'],
            ['status_id' => 'idle', 'status_title' => 'Idle', 'status_color' => 'danger', 'status_icon' => 'heroicon-o-pause-circle'],
            ['status_id' => 'udmt', 'status_title' => 'Under Maintenance', 'status_color' => 'primary', 'status_icon' => 'heroicon-o-wrench-screwdriver'],
            ['status_id' => 'ret', 'status_title' => 'Retired', 'status_color' => 'gray', 'status_icon' => 'heroicon-o-archive-box'],
            ['status_id' => 'dsp', 'status_title' => 'Disposed', 'status_color' => 'danger', 'status_icon' => 'heroicon-o-trash'],
            ['status_id' => 'pnd', 'status_title' => 'Pending', 'status_color' => 'warning', 'status_icon' => 'heroicon-o-clock'],
        ]);
        DB::table('actions')->insert([
            ['a_id' => 'dep', 'a_present_tense' => 'Deploy', 'a_past_tense' => 'Deployed', 'a_icon' => 'heroicon-o-check-circle'],
            ['a_id' => 'dispose', 'a_present_tense' => 'Dispose', 'a_past_tense' => 'Disposed', 'a_icon' => 'heroicon-o-trash'],
        ]);

        $this->migration = require base_path('database/migrations/2026_11_27_141518_update_lifecycle_status_colors_and_icons.php');
    }

    /**
     * @return array<string, string>
     */
    private function colors(): array
    {
        return DB::table('statuses')->pluck('status_color', 'status_id')->all();
    }

    public function test_idle_is_green_in_use_is_blue_maintenance_orange_retired_gray_and_disposed_red(): void
    {
        $this->migration->up();

        $this->assertSame(
            ['alc' => 'primary', 'dep' => 'primary', 'dsp' => 'danger', 'idle' => 'success', 'pnd' => 'warning', 'ret' => 'gray', 'udmt' => 'warning'],
            collect($this->colors())->sortKeys()->all(),
        );
        $this->assertSame('heroicon-o-truck', DB::table('statuses')->where('status_id', 'dep')->value('status_icon'));
        $this->assertSame('heroicon-o-archive-box-x-mark', DB::table('statuses')->where('status_id', 'dsp')->value('status_icon'));
        $this->assertSame('heroicon-o-clock', DB::table('statuses')->where('status_id', 'pnd')->value('status_icon'));
        $this->assertSame('heroicon-o-truck', DB::table('actions')->where('a_id', 'dep')->value('a_icon'));
        $this->assertSame('heroicon-o-archive-box-x-mark', DB::table('actions')->where('a_id', 'dispose')->value('a_icon'));
    }

    public function test_idle_is_renamed_available_and_keeps_its_ids(): void
    {
        DB::table('actions')->insert(['a_id' => 'sidle', 'a_present_tense' => 'Set to Idle', 'a_past_tense' => 'Set to Idle', 'a_icon' => 'heroicon-o-pause-circle']);
        $rename = require base_path('database/migrations/2026_11_27_154507_rename_idle_lifecycle_status_to_available.php');

        $rename->up();

        $this->assertSame('Available', DB::table('statuses')->where('status_id', 'idle')->value('status_title'));
        $this->assertSame(['Set to Available', 'Set to Available'], array_values((array) DB::table('actions')->where('a_id', 'sidle')->first(['a_present_tense', 'a_past_tense'])));
        $this->assertSame('Deployed', DB::table('statuses')->where('status_id', 'dep')->value('status_title'));

        $rename->down();

        $this->assertSame('Idle', DB::table('statuses')->where('status_id', 'idle')->value('status_title'));
        $this->assertSame('Set to Idle', DB::table('actions')->where('a_id', 'sidle')->value('a_present_tense'));
    }

    public function test_rolling_back_restores_the_previous_colors_and_icons(): void
    {
        $before = DB::table('statuses')->orderBy('status_id')->get()->toArray();

        $this->migration->up();
        $this->migration->down();

        $this->assertEquals($before, DB::table('statuses')->orderBy('status_id')->get()->toArray());
        $this->assertSame('heroicon-o-trash', DB::table('actions')->where('a_id', 'dispose')->value('a_icon'));
    }
}
