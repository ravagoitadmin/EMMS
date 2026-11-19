<?php

namespace App\Services\Rfid;

use Illuminate\Support\Facades\DB;

/**
 * The times at which a piece of equipment already has an OUT or an IN: its scans (through any of its tags) and its
 * trips that are not disregarded. The importers use it to leave out a read the system already has.
 */
class RecordedReadTimes
{
    /**
     * @param  list<int>  $assetIds
     * @return array<int, array{OUT?: list<int>, IN?: list<int>}> asset id => status => unix times
     */
    public function forAssets(array $assetIds): array
    {
        $recorded = [];

        foreach (array_chunk($assetIds, 500) as $chunk) {
            $tagOwner = DB::table('asset_tags')->whereIn('asset_parent_id', $chunk)->pluck('asset_parent_id', 'tag_id');

            foreach (array_chunk($tagOwner->keys()->all(), 500) as $tagChunk) {
                foreach (DB::table('asset_tag_logs')->whereIn('tag_id', $tagChunk)->get(['tag_id', 'status', 'detected_at']) as $scan) {
                    $recorded[$tagOwner[$scan->tag_id]][strtoupper($scan->status)][] = strtotime($scan->detected_at);
                }
            }

            foreach (DB::table('asset_movements')->whereIn('asset_id', $chunk)->whereNull('disregarded_at')->get(['asset_id', 'time_out', 'time_in']) as $trip) {
                foreach (['OUT' => $trip->time_out, 'IN' => $trip->time_in] as $status => $time) {
                    if ($time !== null) {
                        $recorded[$trip->asset_id][$status][] = strtotime($time);
                    }
                }
            }
        }

        return $recorded;
    }

    /**
     * Whether $recorded (from forAssets) has a time of this status within $windowSeconds of $at.
     *
     * @param  array{OUT?: list<int>, IN?: list<int>}  $recorded
     */
    public function hasNear(array $recorded, string $status, int $at, int $windowSeconds): bool
    {
        foreach ($recorded[$status] ?? [] as $other) {
            if (abs($other - $at) <= $windowSeconds) {
                return true;
            }
        }

        return false;
    }
}
