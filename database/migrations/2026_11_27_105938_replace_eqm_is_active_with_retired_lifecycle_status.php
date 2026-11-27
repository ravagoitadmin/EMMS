<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'equipment_units_eqm_is_active_index';

    private const REMARK = 'Inactive before the Active/Inactive status was removed';

    /**
     * Removes the Active/Inactive status of assets: every Inactive asset becomes Retired (logged in lifecycle_logs;
     * an allocated accessory is unallocated and keeps its equipment's location), then the column is dropped.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('equipment_units', 'eqm_is_active')) {
            return;
        }

        DB::transaction(function (): void {
            $inactive = DB::table('equipment_units as eu')
                ->leftJoin('equipment_units as parent', 'parent.eqm_id', '=', 'eu.parent_id')
                ->where('eu.eqm_is_active', 0)
                ->get(['eu.eqm_id', 'eu.parent_id', 'eu.location_id', 'parent.location_id as parent_location_id']);

            foreach ($inactive as $asset) {
                DB::table('equipment_units')->where('eqm_id', $asset->eqm_id)->update([
                    'lifecycle_status_id' => 'ret',
                    'parent_id' => null,
                    'location_id' => $asset->parent_id !== null ? ($asset->parent_location_id ?? $asset->location_id) : $asset->location_id,
                ]);
            }

            if (Schema::hasTable('lifecycle_logs')) {
                foreach ($inactive->chunk(500) as $chunk) {
                    DB::table('lifecycle_logs')->insert($chunk->map(fn (object $asset): array => [
                        'asset_id' => $asset->eqm_id,
                        'action_id' => 'retire',
                        'status_id' => 'ret',
                        'remarks' => self::REMARK,
                        'logged_at' => now(),
                    ])->values()->all());
                }
            }
        });

        Schema::table('equipment_units', function (Blueprint $table): void {
            if (Schema::hasIndex('equipment_units', self::INDEX)) {
                $table->dropIndex(self::INDEX);
            }

            $table->dropColumn('eqm_is_active');
        });
    }

    /**
     * Brings the column back: Retired and Disposed assets are Inactive, every other asset Active.
     */
    public function down(): void
    {
        if (Schema::hasColumn('equipment_units', 'eqm_is_active')) {
            return;
        }

        Schema::table('equipment_units', function (Blueprint $table): void {
            $table->boolean('eqm_is_active')->default(true);
            $table->index('eqm_is_active', self::INDEX);
        });

        DB::table('equipment_units')->whereIn('lifecycle_status_id', ['ret', 'dsp'])->update(['eqm_is_active' => 0]);
    }
};
