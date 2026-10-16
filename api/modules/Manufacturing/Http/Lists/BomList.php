<?php

namespace Modules\Manufacturing\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Items\Item;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\CustomFields\BomEntity;
use Modules\Manufacturing\Http\Resources\BomResource;
use Modules\Manufacturing\Models\Bom;

/** MFG-02, MFG-27: BOMs and recipes, by item. */
class BomList extends ManufacturingList
{
    public const RELATIONS = ['company', 'item'];

    /**
     * What a page or an export chunk loads at once: the relations, the
     * versions in force and the drafts (review item 4).
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return [...self::RELATIONS, ...Bom::versionLoads(CarbonImmutable::now())];
    }

    public function name(): string
    {
        return 'manufacturing-boms';
    }

    public function auditAction(): string
    {
        return 'manufacturing.bom.export';
    }

    public function title(array $filters): string
    {
        return __('manufacturing.lists.boms');
    }

    public function resource(Model $model): JsonResource
    {
        return BomResource::make($model);
    }

    public function customFieldEntity(): ?string
    {
        return BomEntity::KEY;
    }

    public function sorts(): array
    {
        return [
            'item' => ListSort::by(['item'], fn ($query, string $direction) => $query->orderBy(
                Item::query()->select('name')->whereColumn('items.id', 'mfg_boms.item_id')->limit(1), $direction)),
            'kind' => ListSort::column('kind'),
            'created_at' => ListSort::column('created_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'item';
    }

    public function columns(): array
    {
        return [
            self::code('code', 'item'),
            self::named('item', 'item'),
            self::enum('kind', 'manufacturing.kinds'),
            ListColumn::text('name', 'manufacturing.columns.name'),
            self::named('company', 'company'),
            ListColumn::make('version', 'manufacturing.columns.version', ['current_version'], fn (array $row) => isset($row['current_version']['version_no']) ? (string) $row['current_version']['version_no'] : null),
            ListColumn::make('effective_from', 'manufacturing.columns.effective_from', ['current_version'], fn (array $row) => $row['current_version']['effective_from'] ?? null),
        ];
    }

    public function exportRelations(): array
    {
        return self::relations();
    }
}
