<?php

namespace App\Models;

use App\Enums\LifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Status extends Model
{
    protected $table = 'statuses';

    protected $primaryKey = 'status_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'status_title',
        'status_color',
        'status_icon',
    ];

    public $timestamps = false;

    /**
     * The lifecycle statuses of assets (Deployed, Allocated, Idle, Under Maintenance, Retired, Disposed) as
     * status_id => title, in the order of the enum. The statuses table also holds work order and maintenance statuses.
     *
     * @return array<string, string>
     */
    public static function lifecycleOptions(): array
    {
        $titles = static::query()->whereIn('status_id', LifecycleStatus::values())->pluck('status_title', 'status_id');

        return collect(LifecycleStatus::values())
            ->filter(fn (string $id): bool => $titles->has($id))
            ->mapWithKeys(fn (string $id): array => [$id => (string) $titles[$id]])
            ->all();
    }

    public function equipmentUnits(): HasMany
    {
        return $this->hasMany(Equipment::class, 'lifecycle_status_id', 'status_id');
    }

    public function maintenanceTasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class, 'mt_status_id', 'status_id');
    }

    public function lifeCycleLogs(): HasMany
    {
        return $this->hasMany(LifecycleLog::class, 'status_id');
    }
}
