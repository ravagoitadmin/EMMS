<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReplaceActiveWithRetiredMigrationTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('equipment_units', function (Blueprint $table): void {
            $table->id('eqm_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('lifecycle_status_id')->nullable()->default('idle');
            $table->string('eqm_name');
            $table->boolean('eqm_is_active')->default(true);
            $table->index('eqm_is_active', 'equipment_units_eqm_is_active_index');
        });

        Schema::create('lifecycle_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('action_id');
            $table->string('status_id');
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('logged_at');
        });

        $this->migration = require base_path('database/migrations/2026_11_27_105938_replace_eqm_is_active_with_retired_lifecycle_status.php');
    }

    public function test_inactive_assets_become_retired_with_a_log_and_the_column_is_dropped(): void
    {
        $crane = DB::table('equipment_units')->insertGetId(['eqm_name' => 'Crane', 'location_id' => 7, 'lifecycle_status_id' => 'dep', 'eqm_is_active' => 1]);
        $oldTruck = DB::table('equipment_units')->insertGetId(['eqm_name' => 'Old truck', 'location_id' => 3, 'lifecycle_status_id' => 'idle', 'eqm_is_active' => 0]);
        $hook = DB::table('equipment_units')->insertGetId(['eqm_name' => 'Hook', 'parent_id' => $crane, 'lifecycle_status_id' => 'alc', 'eqm_is_active' => 0]);

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('equipment_units', 'eqm_is_active'));

        $units = DB::table('equipment_units')->get()->keyBy('eqm_id');
        $this->assertSame('dep', $units[$crane]->lifecycle_status_id);
        $this->assertSame('ret', $units[$oldTruck]->lifecycle_status_id);
        $this->assertSame(3, (int) $units[$oldTruck]->location_id);

        // An allocated accessory is unallocated and stays where its equipment is.
        $this->assertSame('ret', $units[$hook]->lifecycle_status_id);
        $this->assertNull($units[$hook]->parent_id);
        $this->assertSame(7, (int) $units[$hook]->location_id);

        $logs = DB::table('lifecycle_logs')->orderBy('asset_id')->get();
        $this->assertSame([$oldTruck, $hook], $logs->pluck('asset_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(['retire'], $logs->pluck('action_id')->unique()->values()->all());
        $this->assertSame(['ret'], $logs->pluck('status_id')->unique()->values()->all());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        DB::table('equipment_units')->insert(['eqm_name' => 'Old truck', 'eqm_is_active' => 0]);

        $this->migration->up();
        $this->migration->up();

        $this->assertSame(1, DB::table('lifecycle_logs')->count());
    }

    public function test_rolling_back_brings_the_column_back_with_retired_and_disposed_as_inactive(): void
    {
        DB::table('equipment_units')->insert([
            ['eqm_name' => 'In service', 'lifecycle_status_id' => 'dep', 'eqm_is_active' => 1],
            ['eqm_name' => 'Retired', 'lifecycle_status_id' => 'idle', 'eqm_is_active' => 0],
        ]);
        $this->migration->up();
        DB::table('equipment_units')->where('eqm_name', 'In service')->update(['lifecycle_status_id' => 'dsp']);
        DB::table('equipment_units')->insert(['eqm_name' => 'New', 'lifecycle_status_id' => 'idle']);

        $this->migration->down();

        $this->assertSame(
            ['In service' => 0, 'Retired' => 0, 'New' => 1],
            DB::table('equipment_units')->pluck('eqm_is_active', 'eqm_name')->map(fn ($active): int => (int) $active)->all(),
        );
    }
}
