<?php

namespace App\Filament\Widgets;

use App\Enums\LifecycleStatus;
use App\Models\Equipment;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Executive committee: how big the fleet is and what it is doing right now. Read-only, no drill-down links.
 */
class ExecutiveFleetStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    /** The dashboard does not need live numbers: every refresh re-runs this widget's queries. */
    protected ?string $pollingInterval = '120s';

    protected ?string $heading = 'Fleet Overview';

    protected ?string $description = 'All registered assets and what they are doing.';

    public static function canView(): bool
    {
        return Auth::user()?->hasRole('execom') ?? false;
    }

    protected function getStats(): array
    {
        // Counted over every asset, so kept for a minute instead of being counted again on every refresh.
        $row = (object) Cache::remember('dashboard.fleet_overview', now()->addSeconds(60), fn (): array => (array) Equipment::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(lifecycle_status_id IN (?, ?)) as out_of_service', LifecycleStatus::outOfService())
            ->selectRaw('SUM(lifecycle_status_id = ?) as deployed', [LifecycleStatus::Deployed->value])
            ->selectRaw('SUM(lifecycle_status_id = ?) as maintenance', [LifecycleStatus::UnderMaintenance->value])
            ->selectRaw('SUM(lifecycle_status_id = ?) as idle', [LifecycleStatus::Idle->value])
            ->toBase()
            ->first());

        $total = (int) ($row->total ?? 0);
        $outOfService = (int) ($row->out_of_service ?? 0);
        $share = fn (int $count): string => $total > 0 ? round($count / $total * 100).'% of all assets' : '—';

        return [
            Stat::make('Total assets', $total)
                ->icon(Heroicon::CubeTransparent)
                ->description(($total - $outOfService).' in service, '.$outOfService.' retired or disposed')
                ->color('gray'),
            Stat::make('Deployed', (int) ($row->deployed ?? 0))
                ->icon(Heroicon::Truck)
                ->description($share((int) ($row->deployed ?? 0)))
                ->color('primary'),
            Stat::make('Under maintenance', (int) ($row->maintenance ?? 0))
                ->icon(Heroicon::WrenchScrewdriver)
                ->description($share((int) ($row->maintenance ?? 0)))
                ->color('warning'),
            Stat::make('Idle', (int) ($row->idle ?? 0))
                ->icon(Heroicon::PauseCircle)
                ->description($share((int) ($row->idle ?? 0)))
                ->color('success'),
        ];
    }
}
