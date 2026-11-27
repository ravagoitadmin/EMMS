<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Enums\LifecycleStatus;
use App\Filament\Exports\EquipmentExporter;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Support\ExportPreviewAction;
use App\Models\AssetType;
use App\Models\Equipment;
use App\Models\Status;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListEquipment extends ListRecords
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ExportPreviewAction::make()
                ->exporter(EquipmentExporter::class)
                ->authorize(fn () => Auth::user()->can('previewExport', Equipment::class)),
            ExportAction::make()
                ->exporter(EquipmentExporter::class)
                ->color('gray')
                ->authorize(fn () => Auth::user()->can('export', Equipment::class)),
        ];
    }

    /** @var array<string, int>|null number of assets per lifecycle status id, plus 'total' */
    protected ?array $tabCounts = null;

    /**
     * One grouped query for all the tab badges. It starts from the resource query, so the Equipment,
     * Accessories and Service Vehicles pages count only their own assets, the same rows the table shows.
     *
     * @return array<string, int>
     */
    protected function tabCounts(): array
    {
        if ($this->tabCounts === null) {
            $counts = static::getResource()::getEloquentQuery()
                ->withoutEagerLoads()
                ->toBase()
                ->selectRaw('lifecycle_status_id, COUNT(*) as aggregate')
                ->groupBy('lifecycle_status_id')
                ->pluck('aggregate', 'lifecycle_status_id')
                ->map(fn ($count): int => (int) $count);

            $this->tabCounts = ['total' => (int) $counts->sum()] + $counts->all();
        }

        return $this->tabCounts;
    }

    /**
     * The lifecycle statuses an asset on this page can have: equipment and service vehicles are deployed,
     * accessories are allocated to equipment instead (the all-assets page shows both).
     *
     * @return list<LifecycleStatus>
     */
    protected function lifecycleTabs(): array
    {
        $inUse = match (EquipmentResource::currentAssetTypeCode()) {
            AssetType::ACCESSORY => [LifecycleStatus::Allocated],
            AssetType::EQUIPMENT, AssetType::SERVICE_VEHICLE => [LifecycleStatus::Deployed],
            default => [LifecycleStatus::Deployed, LifecycleStatus::Allocated],
        };

        return [...$inUse, LifecycleStatus::Idle, LifecycleStatus::UnderMaintenance, LifecycleStatus::Retired, LifecycleStatus::Disposed];
    }

    public function getTabs(): array
    {
        $titles = Status::lifecycleOptions();
        $tabs = [
            'all' => Tab::make('All')
                ->badge(fn () => $this->tabCounts()['total']),
        ];

        foreach ($this->lifecycleTabs() as $status) {
            $tabs[str($status->name)->snake()->toString()] = Tab::make($titles[$status->value] ?? str($status->name)->headline()->toString())
                ->badge(fn () => $this->tabCounts()[$status->value] ?? 0)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('lifecycle_status_id', $status->value));
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
