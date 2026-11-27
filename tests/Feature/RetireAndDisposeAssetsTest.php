<?php

namespace Tests\Feature;

use App\Enums\LifecycleStatus;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Equipment\Pages\ViewEquipment;
use App\Models\Action as ModelsAction;
use App\Models\AppUser;
use App\Models\AssetType;
use App\Models\Equipment;
use App\Models\LifecycleLog;
use App\Models\Location;
use App\Models\Status;
use App\Policies\EquipmentPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RetireAndDisposeAssetsTest extends TestCase
{
    private AppUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['activitylog.enabled' => false]);

        (require base_path('database/migrations/2026_04_24_054941_create_permission_tables.php'))->up();

        Schema::create('app_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->string('user_fname')->nullable();
            $table->string('user_lname')->nullable();
            $table->string('user_email')->nullable();
            $table->unsignedBigInteger('user_dep_id')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('asset_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name')->unique();
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
        });

        Schema::create('equipment_units', function (Blueprint $table): void {
            $table->id('eqm_id');
            $table->foreignId('asset_type_id')->nullable()->constrained('asset_types');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('lifecycle_status_id')->nullable()->default('idle');
            $table->unsignedBigInteger('eqm_eqmm_id')->nullable();
            $table->string('eqm_name');
            $table->string('eqm_prc_code')->nullable()->unique();
        });

        Schema::create('lifecycle_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('action_id');
            $table->string('status_id');
            $table->unsignedBigInteger('deploy_to_loc_id')->nullable();
            $table->string('deploy_to_loc_name')->nullable();
            $table->unsignedBigInteger('allocate_to_equipment_id')->nullable();
            $table->string('allocate_to_equipment_name')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('performed_by_name')->nullable();
            $table->timestamp('logged_at');
        });

        Schema::create('actions', function (Blueprint $table): void {
            $table->string('a_id')->primary();
            $table->string('a_present_tense')->nullable();
            $table->string('a_past_tense')->nullable();
            $table->string('a_icon')->nullable();
        });

        Schema::create('statuses', function (Blueprint $table): void {
            $table->string('status_id')->primary();
            $table->string('status_title')->nullable();
            $table->string('status_color')->nullable();
            $table->string('status_icon')->nullable();
        });

        Schema::create('asset_edit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->json('changes');
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('logged_at');
        });

        DB::table('statuses')->insert([
            ['status_id' => 'pnd', 'status_title' => 'Pending', 'status_color' => 'warning', 'status_icon' => ''],
            ['status_id' => 'idle', 'status_title' => 'Idle', 'status_color' => 'danger', 'status_icon' => ''],
            ['status_id' => 'dep', 'status_title' => 'Deployed', 'status_color' => 'success', 'status_icon' => ''],
        ]);
        DB::table('actions')->insert(['a_id' => 'dep', 'a_present_tense' => 'Deploy', 'a_past_tense' => 'Deployed', 'a_icon' => 'heroicon-o-truck']);

        // The new rows and permissions come from the migrations themselves.
        (require base_path('database/migrations/2026_11_27_105934_add_retired_and_disposed_lifecycle_statuses.php'))->up();
        foreach (['super_admin', 'asset_admin', 'asset_manager', 'manager'] as $role) {
            DB::table('roles')->insert(['name' => $role, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }
        (require base_path('database/migrations/2026_11_27_105936_add_retire_and_dispose_permissions_to_equipment.php'))->up();
        (require base_path('database/migrations/2026_11_27_114510_grant_retire_and_dispose_to_asset_admin_and_asset_manager_only.php'))->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AssetType::flushIdCache();

        $this->user = AppUser::create(['user_fname' => 'Alma', 'user_lname' => 'Admin', 'user_email' => 'alma@example.test', 'user_dep_id' => 1, 'is_active' => true]);
        $this->user->assignRole('asset_admin');
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        AssetType::flushIdCache();

        parent::tearDown();
    }

    private function transition(Equipment $asset, string $method, string $actionId, LifecycleStatus|string $status, array $data = []): void
    {
        $page = new ViewEquipment;
        $page->record = $asset;

        (new ReflectionMethod($page, $method))->invoke($page, ModelsAction::query()->findOrFail($actionId), $status, $data);
    }

    public function test_the_migrations_add_retired_disposed_and_their_actions_and_permissions(): void
    {
        $this->assertSame(['Retired', 'Disposed'], Status::query()->whereIn('status_id', ['ret', 'dsp'])->orderByDesc('status_id')->pluck('status_title')->all());
        $this->assertSame(['dispose', 'retire'], ModelsAction::query()->whereIn('a_id', ['retire', 'dispose'])->orderBy('a_id')->pluck('a_id')->all());
        $this->assertTrue($this->user->can('Retire:EquipmentResource'));
        $this->assertTrue($this->user->can('Dispose:EquipmentResource'));
    }

    public function test_by_default_only_asset_admin_and_asset_manager_can_retire_and_dispose(): void
    {
        $holders = fn (string $permission): array => DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('permissions.name', $permission)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();

        $this->assertSame(['asset_admin', 'asset_manager'], $holders('Retire:EquipmentResource'));
        $this->assertSame(['asset_admin', 'asset_manager'], $holders('Dispose:EquipmentResource'));
    }

    public function test_the_roles_screen_lists_retire_and_dispose_for_the_equipment_resource(): void
    {
        $manage = config('filament-shield.resources.manage')[EquipmentResource::class];

        $this->assertContains('retire', $manage);
        $this->assertContains('dispose', $manage);
    }

    public function test_the_lifecycle_filter_lists_only_lifecycle_statuses_in_order(): void
    {
        $this->assertSame(['dep' => 'Deployed', 'idle' => 'Idle', 'ret' => 'Retired', 'dsp' => 'Disposed'], Status::lifecycleOptions());
    }

    public function test_in_service_leaves_out_retired_and_disposed_assets(): void
    {
        $idle = Equipment::factory()->equipment()->create(['lifecycle_status_id' => 'idle']);
        $none = Equipment::factory()->equipment()->create(['lifecycle_status_id' => null]);
        $retired = Equipment::factory()->equipment()->retired()->create();
        $disposed = Equipment::factory()->equipment()->disposed()->create();

        $this->assertEqualsCanonicalizing([$idle->eqm_id, $none->eqm_id], Equipment::query()->inService()->pluck('eqm_id')->all());
        $this->assertEqualsCanonicalizing([$retired->eqm_id, $disposed->eqm_id], Equipment::query()->outOfService()->pluck('eqm_id')->all());
        $this->assertTrue($idle->isInService());
        $this->assertFalse($retired->isInService());
        $this->assertTrue($disposed->isDisposed());
    }

    public function test_an_asset_in_service_can_be_retired_or_disposed(): void
    {
        $asset = Equipment::factory()->equipment()->create(['lifecycle_status_id' => 'dep']);
        $policy = new EquipmentPolicy;

        $this->assertTrue($policy->retire($this->user, $asset));
        $this->assertTrue($policy->dispose($this->user, $asset));
    }

    public function test_a_retired_asset_can_be_put_back_in_service_or_disposed_but_not_retired_again(): void
    {
        $asset = Equipment::factory()->equipment()->retired()->create();
        $policy = new EquipmentPolicy;
        $this->user->givePermissionTo(Permission::findOrCreate('Deploy:EquipmentResource', 'web'), Permission::findOrCreate('SetToIdle:EquipmentResource', 'web'));
        $this->user->refresh();

        $this->assertFalse($policy->retire($this->user, $asset));
        $this->assertTrue($policy->dispose($this->user, $asset));
        $this->assertTrue($policy->deploy($this->user, $asset));
        $this->assertTrue($policy->setToIdle($this->user, $asset));
    }

    public function test_a_disposed_asset_gets_no_lifecycle_action_at_all(): void
    {
        $asset = Equipment::factory()->equipment()->disposed()->create();
        $policy = new EquipmentPolicy;
        foreach (['Deploy', 'SetToIdle', 'SetToMaintenance', 'Allocate'] as $permission) {
            $this->user->givePermissionTo(Permission::findOrCreate("{$permission}:EquipmentResource", 'web'));
        }
        $this->user->refresh();

        foreach (['retire', 'dispose', 'deploy', 'setToIdle', 'setToMaintenance', 'allocate'] as $ability) {
            $this->assertFalse($policy->{$ability}($this->user, $asset), "{$ability} must be refused for a disposed asset");
        }
    }

    public function test_retire_and_dispose_need_their_permission(): void
    {
        $asset = Equipment::factory()->equipment()->create();
        $other = AppUser::create(['user_fname' => 'No', 'user_lname' => 'Rights', 'user_email' => 'no@example.test', 'user_dep_id' => 1, 'is_active' => true]);
        $policy = new EquipmentPolicy;

        $this->assertFalse($policy->retire($other, $asset));
        $this->assertFalse($policy->dispose($other, $asset));
    }

    public function test_retiring_keeps_the_location_and_logs_the_action(): void
    {
        $yard = Location::create(['name' => 'North Yard']);
        $asset = Equipment::factory()->equipment()->create(['lifecycle_status_id' => 'idle', 'location_id' => $yard->id]);

        $this->transition($asset, 'saveOutOfServiceTransition', 'retire', LifecycleStatus::Retired, ['remarks' => 'Too old']);

        $asset->refresh();
        $this->assertSame('ret', $asset->lifecycle_status_id);
        $this->assertSame($yard->id, $asset->location_id);

        $log = LifecycleLog::query()->where('asset_id', $asset->eqm_id)->sole();
        $this->assertSame(['retire', 'ret', 'Too old', $this->user->getKey()], [$log->action_id, $log->status_id, $log->remarks, $log->performed_by]);
    }

    public function test_disposing_an_allocated_accessory_unallocates_it_where_its_equipment_is(): void
    {
        $site = Location::create(['name' => 'Site 22']);
        $crane = Equipment::factory()->equipment()->create(['eqm_name' => 'Crane', 'location_id' => $site->id]);
        $hook = Equipment::factory()->accessory($crane)->create(['lifecycle_status_id' => 'alc']);

        $this->transition($hook, 'saveOutOfServiceTransition', 'dispose', LifecycleStatus::Disposed, ['remarks' => 'Cracked, scrapped']);

        $hook->refresh();
        $this->assertSame('dsp', $hook->lifecycle_status_id);
        $this->assertNull($hook->parent_id);
        $this->assertSame($site->id, $hook->location_id);
    }

    public function test_equipment_with_allocated_accessories_is_not_retired(): void
    {
        $crane = Equipment::factory()->equipment()->create(['lifecycle_status_id' => 'dep']);
        Equipment::factory()->accessory($crane)->create(['lifecycle_status_id' => 'alc']);

        $this->transition($crane, 'saveOutOfServiceTransition', 'retire', LifecycleStatus::Retired);

        $this->assertSame('dep', $crane->refresh()->lifecycle_status_id);
        $this->assertSame(0, LifecycleLog::query()->count());
    }

    public function test_a_retired_asset_can_be_deployed_again(): void
    {
        $asset = Equipment::factory()->equipment()->retired()->create();

        $this->transition($asset, 'saveLifecycleTransition', 'dep', 'dep');

        $this->assertSame('dep', $asset->refresh()->lifecycle_status_id);
        $this->assertTrue($asset->isInService());
    }
}
