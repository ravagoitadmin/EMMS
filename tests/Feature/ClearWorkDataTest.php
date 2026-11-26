<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClearWorkDataTest extends TestCase
{
    /** Tables the command empties, each with one column besides its id. */
    private const CLEARED = [
        'worker_reports', 'report_submissions', 'work_order_notes', 'work_order_assignments', 'work_order_log_updates',
        'work_order_logs', 'inspection_item_logs', 'maintenance_task_logs', 'work_orders', 'inspection_items',
        'inspections', 'maintenance_inspection_logs', 'maintenance_inspection_items', 'maintenance_inspections',
        'maintenance_tasks', 'equipment_tasks_schedules',
    ];

    /** Tables that must be left alone. */
    private const KEPT = ['app_users', 'aims_inspections', 'tasks', 'equipment_type_task_checklist_template', 'activity_log'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['activitylog.enabled' => false]);
        Storage::fake('local');

        foreach ([...self::CLEARED, ...self::KEPT] as $table) {
            Schema::create($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->id();
                $blueprint->string('note')->nullable();

                match ($table) {
                    'work_orders' => $blueprint->json('wo_attachments')->nullable(),
                    'work_order_log_updates' => $blueprint->json('wolu_attachments')->nullable(),
                    'work_order_notes' => $blueprint->json('won_attachments')->nullable(),
                    default => null,
                };
            });

            DB::table($table)->insert(['note' => $table]);
        }

        DB::table('work_orders')->update(['wo_attachments' => json_encode(['wo-photo.jpg', 'missing.pdf'])]);
        DB::table('work_order_log_updates')->update(['wolu_attachments' => json_encode(['work-order-replies/WO-ELEC-261101001/reply.png'])]);
        DB::table('work_order_notes')->update(['won_attachments' => json_encode('note.jpg')]);

        Storage::disk('local')->put('wo-photo.jpg', 'x');
        Storage::disk('local')->put('work-order-replies/WO-ELEC-261101001/reply.png', 'x');
        Storage::disk('local')->put('note.jpg', 'x');
        Storage::disk('local')->put('site-logo/logo.png', 'x');
    }

    private function assertNothingDeleted(): void
    {
        foreach ([...self::CLEARED, ...self::KEPT] as $table) {
            $this->assertSame(1, DB::table($table)->count(), "{$table} was changed");
        }

        Storage::disk('local')->assertExists(['wo-photo.jpg', 'work-order-replies/WO-ELEC-261101001/reply.png', 'note.jpg']);
    }

    public function test_the_preview_counts_rows_and_files_and_deletes_nothing(): void
    {
        $this->artisan('work-data:clear')
            ->expectsOutputToContain('Attached files: 3 found on disk (4 listed).')
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();

        $this->assertNothingDeleted();
    }

    public function test_execute_empties_the_work_data_and_its_files_and_keeps_the_rest(): void
    {
        $this->artisan('work-data:clear', ['--execute' => true, '--release' => '1.0.0'])
            ->expectsQuestion('Type DELETE to continue', 'DELETE')
            ->expectsOutputToContain('Deleted 16 rows and 3 files.')
            ->expectsOutputToContain('come back at the next sync')
            ->assertSuccessful();

        foreach (self::CLEARED as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} was not emptied");
        }

        foreach (self::KEPT as $table) {
            $this->assertSame(1, DB::table($table)->count(), "{$table} was changed");
        }

        Storage::disk('local')->assertMissing(['wo-photo.jpg', 'work-order-replies/WO-ELEC-261101001/reply.png', 'note.jpg']);
        Storage::disk('local')->assertExists('site-logo/logo.png');
    }

    public function test_anything_but_the_exact_confirmation_word_deletes_nothing(): void
    {
        $this->artisan('work-data:clear', ['--execute' => true])
            ->expectsQuestion('Type DELETE to continue', 'yes')
            ->expectsOutputToContain('Cancelled')
            ->assertFailed();

        $this->assertNothingDeleted();
    }

    public function test_the_activity_log_records_the_clean_start_with_the_release(): void
    {
        config(['activitylog.enabled' => true]);
        Schema::drop('activity_log');
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });

        $this->artisan('work-data:clear', ['--execute' => true, '--force' => true, '--release' => '1.0.0'])->assertSuccessful();

        $entry = DB::table('activity_log')->latest('id')->first();
        $this->assertSame('Cleared all work orders, inspections and maintenance data for release 1.0.0', $entry->description);
        $this->assertSame('1.0.0', json_decode($entry->properties, true)['release']);
    }

    public function test_an_empty_database_has_nothing_to_delete(): void
    {
        foreach (self::CLEARED as $table) {
            DB::table($table)->delete();
        }

        $this->artisan('work-data:clear', ['--execute' => true])
            ->expectsOutputToContain('Nothing to delete.')
            ->assertSuccessful();
    }
}
