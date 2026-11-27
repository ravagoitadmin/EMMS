<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<array{status_id: string, status_title: string, status_color: string, status_icon: string}> */
    private const STATUSES = [
        ['status_id' => 'ret', 'status_title' => 'Retired', 'status_color' => 'gray', 'status_icon' => 'heroicon-o-archive-box'],
        ['status_id' => 'dsp', 'status_title' => 'Disposed', 'status_color' => 'danger', 'status_icon' => 'heroicon-o-trash'],
    ];

    /** @var list<array{a_id: string, a_present_tense: string, a_past_tense: string, a_icon: string}> */
    private const ACTIONS = [
        ['a_id' => 'retire', 'a_present_tense' => 'Retire', 'a_past_tense' => 'Retired', 'a_icon' => 'heroicon-o-archive-box'],
        ['a_id' => 'dispose', 'a_present_tense' => 'Dispose', 'a_past_tense' => 'Disposed', 'a_icon' => 'heroicon-o-trash'],
    ];

    /**
     * The two lifecycle statuses that replace "Inactive": Retired (can be put back in service) and Disposed (final),
     * with the actions logged when an asset is moved to them.
     */
    public function up(): void
    {
        if (Schema::hasTable('statuses')) {
            DB::table('statuses')->insertOrIgnore(self::STATUSES);
        }

        if (Schema::hasTable('actions')) {
            DB::table('actions')->insertOrIgnore(self::ACTIONS);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('actions')) {
            DB::table('actions')->whereIn('a_id', array_column(self::ACTIONS, 'a_id'))->delete();
        }

        if (Schema::hasTable('statuses')) {
            DB::table('statuses')->whereIn('status_id', array_column(self::STATUSES, 'status_id'))->delete();
        }
    }
};
