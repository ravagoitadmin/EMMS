<?php

namespace Tests\Feature;

use App\Filament\Resources\Equipment\Schemas\EquipmentInfolist;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Tests\TestCase;

class AssetDetailsOrderTest extends TestCase
{
    public function test_the_asset_details_fill_the_left_column_first_then_the_right_one(): void
    {
        $section = collect(EquipmentInfolist::configure(Schema::make())->getComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Section && $component->getHeading() === 'Asset Details');

        $this->assertNotNull($section, 'The Asset Details section is missing.');

        $columns = collect($section->getDefaultChildComponents())
            ->map(fn ($column): array => $column instanceof Group
                ? collect($column->getDefaultChildComponents())
                    ->filter(fn ($component): bool => $component instanceof Entry)
                    ->map(fn (Entry $entry): string => (string) $entry->getLabel())
                    ->values()
                    ->all()
                : [])
            ->all();

        $this->assertSame([
            ['Asset Code', 'Asset Name', 'Lifecycle Status', 'Asset Type', 'Equipment Type', 'Brand', 'Model'],
            // "Allocated to" shows for accessories only: the equipment it belongs to, where its location comes from.
            ['Year Model', 'Date Purchased', 'Plate Number', 'Chassis #', 'Location', 'Allocated to', 'Tags'],
        ], $columns);
    }
}
