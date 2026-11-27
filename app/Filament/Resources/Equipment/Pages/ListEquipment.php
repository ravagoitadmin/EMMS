<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Enums\LifecycleStatus;
use App\Filament\Exports\EquipmentExporter;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Support\ExportPreviewAction;
use App\Models\Equipment;
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

    /** @var array{total:int, active:int, inactive:int}|null */
    protected ?array $tabCounts = null;

    /**
     * One query for all four tab badges instead of four. It starts from the resource query, so the
     * Equipment and Accessories pages count only their own assets, the same rows the table shows.
     *
     * @return array{total: int, in_service: int, retired: int, disposed: int}
     */
    protected function tabCounts(): array
    {
        if ($this->tabCounts === null) {
            $row = static::getResource()::getEloquentQuery()
                ->withoutEagerLoads()
                ->selectRaw('COUNT(*) as total, SUM(lifecycle_status_id = ?) as retired, SUM(lifecycle_status_id = ?) as disposed', [
                    LifecycleStatus::Retired->value,
                    LifecycleStatus::Disposed->value,
                ])
                ->first();

            $this->tabCounts = [
                'total' => (int) $row->total,
                'in_service' => (int) $row->total - (int) $row->retired - (int) $row->disposed,
                'retired' => (int) $row->retired,
                'disposed' => (int) $row->disposed,
            ];
        }

        return $this->tabCounts;
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(fn () => $this->tabCounts()['total']),

            'in_service' => Tab::make('In service')
                ->badge(fn () => $this->tabCounts()['in_service'])
                ->modifyQueryUsing(fn (Builder $query) => $query->inService()),

            'retired' => Tab::make('Retired')
                ->badge(fn () => $this->tabCounts()['retired'])
                ->modifyQueryUsing(fn (Builder $query) => $query->where('lifecycle_status_id', LifecycleStatus::Retired->value)),

            'disposed' => Tab::make('Disposed')
                ->badge(fn () => $this->tabCounts()['disposed'])
                ->modifyQueryUsing(fn (Builder $query) => $query->where('lifecycle_status_id', LifecycleStatus::Disposed->value)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'in_service';
    }
}
