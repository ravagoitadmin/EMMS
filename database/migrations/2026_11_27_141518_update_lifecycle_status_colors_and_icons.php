<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lifecycle badges: in use (deployed / allocated) is blue, idle green, under maintenance orange, retired gray,
     * disposed red. [status_id => [new color, new icon, old color, old icon]]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const STATUSES = [
        'dep' => ['primary', 'heroicon-o-truck', 'success', 'heroicon-o-check-circle'],
        'alc' => ['primary', 'heroicon-o-link', 'success', 'heroicon-o-check-circle'],
        'idle' => ['success', 'heroicon-o-pause-circle', 'danger', 'heroicon-o-pause-circle'],
        'udmt' => ['warning', 'heroicon-o-wrench-screwdriver', 'primary', 'heroicon-o-wrench-screwdriver'],
        'ret' => ['gray', 'heroicon-o-archive-box', 'gray', 'heroicon-o-archive-box'],
        'dsp' => ['danger', 'heroicon-o-archive-box-x-mark', 'danger', 'heroicon-o-trash'],
    ];

    /**
     * The icons of the matching lifecycle actions (menu items and lifecycle log). [a_id => [new icon, old icon]]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ACTIONS = [
        'dep' => ['heroicon-o-truck', 'heroicon-o-check-circle'],
        'alc' => ['heroicon-o-link', 'heroicon-o-puzzle-piece'],
        'sidle' => ['heroicon-o-pause-circle', 'heroicon-o-pause-circle'],
        'smt' => ['heroicon-o-wrench-screwdriver', 'heroicon-o-wrench-screwdriver'],
        'retire' => ['heroicon-o-archive-box', 'heroicon-o-archive-box'],
        'dispose' => ['heroicon-o-archive-box-x-mark', 'heroicon-o-trash'],
    ];

    public function up(): void
    {
        $this->apply(newValues: true);
    }

    public function down(): void
    {
        $this->apply(newValues: false);
    }

    private function apply(bool $newValues): void
    {
        if (Schema::hasTable('statuses')) {
            foreach (self::STATUSES as $id => [$color, $icon, $oldColor, $oldIcon]) {
                DB::table('statuses')->where('status_id', $id)->update([
                    'status_color' => $newValues ? $color : $oldColor,
                    'status_icon' => $newValues ? $icon : $oldIcon,
                ]);
            }
        }

        if (Schema::hasTable('actions')) {
            foreach (self::ACTIONS as $id => [$icon, $oldIcon]) {
                DB::table('actions')->where('a_id', $id)->update(['a_icon' => $newValues ? $icon : $oldIcon]);
            }
        }
    }
};
