<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Idle" is shown as "Available". Only the names change: the ids (status idle, action sidle) stay, so stored
     * records, filters and the equipments sheet (which holds the ids) are unaffected.
     */
    public function up(): void
    {
        $this->rename(status: 'Available', present: 'Set to Available', past: 'Set to Available');
    }

    public function down(): void
    {
        $this->rename(status: 'Idle', present: 'Set to Idle', past: 'Set to Idle');
    }

    private function rename(string $status, string $present, string $past): void
    {
        if (Schema::hasTable('statuses')) {
            DB::table('statuses')->where('status_id', 'idle')->update(['status_title' => $status]);
        }

        if (Schema::hasTable('actions')) {
            DB::table('actions')->where('a_id', 'sidle')->update(['a_present_tense' => $present, 'a_past_tense' => $past]);
        }
    }
};
