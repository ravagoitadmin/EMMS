<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = ['Retire:EquipmentResource', 'Dispose:EquipmentResource'];

    /** Roles that start out able to retire and dispose assets; any other role gets it by ticking it in Shield. */
    private const ROLES = ['super_admin', 'asset_admin'];

    /**
     * The "Retire" and "Dispose" actions on an asset (they replace setting it Inactive).
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionId = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');

            foreach (self::ROLES as $roleName) {
                $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');

                if ($roleId !== null && $permissionId !== null) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
