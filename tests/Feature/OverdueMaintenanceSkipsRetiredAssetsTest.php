<?php

namespace Tests\Feature;

use App\Mail\MaintenanceTaskDueMail;
use App\Models\AppUser;
use App\Services\Maintenance\OverdueMaintenanceNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OverdueMaintenanceSkipsRetiredAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['activitylog.enabled' => false]);

        (require base_path('database/migrations/2026_04_24_054941_create_permission_tables.php'))->up();

        Schema::create('departments', function (Blueprint $table): void {
            $table->id('dep_id');
            $table->string('dep_code')->nullable();
            $table->string('dep_name')->nullable();
            $table->boolean('is_maintenance')->default(false);
        });

        Schema::create('app_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->string('user_fname')->nullable();
            $table->string('user_lname')->nullable();
            $table->string('user_email')->nullable();
            $table->unsignedBigInteger('user_dep_id')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('equipment_units', function (Blueprint $table): void {
            $table->id('eqm_id');
            $table->string('eqm_name');
            $table->string('lifecycle_status_id')->nullable();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id('task_id');
            $table->string('task_name');
        });

        Schema::create('statuses', function (Blueprint $table): void {
            $table->string('status_id')->primary();
            $table->string('status_title');
        });

        Schema::create('maintenance_tasks', function (Blueprint $table): void {
            $table->id('mt_id');
            $table->unsignedBigInteger('mt_eqm_id');
            $table->unsignedBigInteger('mt_dep_id');
            $table->unsignedBigInteger('mt_task_id');
            $table->string('mt_task_log')->nullable();
            $table->string('mt_status_id');
            $table->dateTime('mt_due_dt')->nullable();
            $table->dateTime('mt_closed_dt')->nullable();
            $table->date('mt_overdue_notified_dt')->nullable();
        });

        DB::table('departments')->insert(['dep_id' => 2, 'dep_code' => 'MECH', 'dep_name' => 'Mechanical', 'is_maintenance' => 1]);
        DB::table('tasks')->insert(['task_id' => 1, 'task_name' => 'Change oil']);
        DB::table('statuses')->insert(['status_id' => 'pnd', 'status_title' => 'Pending']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::create(['name' => 'manager', 'guard_name' => 'web']);
        AppUser::create(['user_fname' => 'Liza', 'user_lname' => 'Ramos', 'user_email' => 'liza@example.test', 'user_dep_id' => 2, 'is_active' => true])
            ->assignRole('manager');
    }

    private function overdueTaskOn(string $unit, ?string $lifecycle): int
    {
        $unitId = DB::table('equipment_units')->insertGetId(['eqm_name' => $unit, 'lifecycle_status_id' => $lifecycle]);

        return DB::table('maintenance_tasks')->insertGetId([
            'mt_eqm_id' => $unitId, 'mt_dep_id' => 2, 'mt_task_id' => 1, 'mt_task_log' => 'Change oil',
            'mt_status_id' => 'pnd', 'mt_due_dt' => '2026-11-20 17:00:00',
        ]);
    }

    public function test_overdue_reminders_leave_out_retired_and_disposed_assets(): void
    {
        $inService = $this->overdueTaskOn('Forklift 1', 'idle');
        $noStatus = $this->overdueTaskOn('Forklift 2', null);
        $retired = $this->overdueTaskOn('Old crane', 'ret');
        $disposed = $this->overdueTaskOn('Sold truck', 'dsp');

        $sent = app(OverdueMaintenanceNotificationService::class)->notify(Carbon::parse('2026-11-27 08:00:00'));

        $this->assertSame(1, $sent);
        Mail::assertQueued(MaintenanceTaskDueMail::class, fn (MaintenanceTaskDueMail $mail): bool => $mail->tasks->pluck('mt_id')->sort()->values()->all() === [$inService, $noStatus]);
        $this->assertSame([$inService, $noStatus], DB::table('maintenance_tasks')->whereNotNull('mt_overdue_notified_dt')->orderBy('mt_id')->pluck('mt_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNull(DB::table('maintenance_tasks')->where('mt_id', $retired)->value('mt_overdue_notified_dt'));
        $this->assertNull(DB::table('maintenance_tasks')->where('mt_id', $disposed)->value('mt_overdue_notified_dt'));
    }
}
