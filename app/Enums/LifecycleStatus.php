<?php

namespace App\Enums;

/**
 * The lifecycle status of an asset (equipment_units.lifecycle_status_id, a statuses.status_id).
 *
 * Retired and Disposed assets are out of service: they get no new work orders, trips, maintenance
 * reminders or category assignments. A Retired asset can be put back in service; Disposed is final.
 */
enum LifecycleStatus: string
{
    case Deployed = 'dep';
    case Allocated = 'alc';
    case Idle = 'idle';
    case UnderMaintenance = 'udmt';
    case Retired = 'ret';
    case Disposed = 'dsp';

    /**
     * The action logged in lifecycle_logs when an asset is moved to this status (an actions.a_id).
     */
    public function actionId(): string
    {
        return match ($this) {
            self::Deployed => 'dep',
            self::Allocated => 'alc',
            self::Idle => 'sidle',
            self::UnderMaintenance => 'smt',
            self::Retired => 'retire',
            self::Disposed => 'dispose',
        };
    }

    /**
     * The name shown when the statuses table has no title for it (the table's status_title is used first).
     * Idle is shown as "Available"; its id stays 'idle'.
     */
    public function label(): string
    {
        return match ($this) {
            self::Deployed => 'Deployed',
            self::Allocated => 'Allocated',
            self::Idle => 'Available',
            self::UnderMaintenance => 'Under Maintenance',
            self::Retired => 'Retired',
            self::Disposed => 'Disposed',
        };
    }

    public function isOutOfService(): bool
    {
        return in_array($this, [self::Retired, self::Disposed], true);
    }

    /**
     * Status ids of assets that are no longer in service.
     *
     * @return list<string>
     */
    public static function outOfService(): array
    {
        return [self::Retired->value, self::Disposed->value];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
