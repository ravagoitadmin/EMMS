<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = ['Retire:EquipmentResource', 'Dispose:EquipmentResource'];

    /** The roles that start out with Retire and Dispose; any other role gets them by ticking them in Roles. */
    private const ROLES = ['asset_admin', 'asset_manager'];

    /** Had them from the first migration; it now gets them only when they are ticked for it in Roles. */
    private const NO_LONGER_BY_DEFAULT = 'super_admin';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $permissionIds = $this->permissionIds();

        foreach (self::ROLES as $roleName) {
            $roleId = $this->roleId($roleName);

            foreach ($roleId === null ? [] : $permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $superAdminId = $this->roleId(self::NO_LONGER_BY_DEFAULT);

        if ($superAdminId !== null) {
            DB::table('role_has_permissions')->where('role_id', $superAdminId)->whereIn('permission_id', $permissionIds)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Back to what the first migration granted: super_admin and asset_admin.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $permissionIds = $this->permissionIds();
        $assetManagerId = $this->roleId('asset_manager');
        $superAdminId = $this->roleId(self::NO_LONGER_BY_DEFAULT);

        if ($assetManagerId !== null) {
            DB::table('role_has_permissions')->where('role_id', $assetManagerId)->whereIn('permission_id', $permissionIds)->delete();
        }

        foreach ($superAdminId === null ? [] : $permissionIds as $permissionId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $superAdminId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<int>
     */
    private function permissionIds(): array
    {
        return DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function roleId(string $name): ?int
    {
        $id = DB::table('roles')->where('name', $name)->where('guard_name', 'web')->value('id');

        return $id === null ? null : (int) $id;
    }
};
