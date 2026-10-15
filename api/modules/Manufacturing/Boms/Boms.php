<?php

namespace Modules\Manufacturing\Boms;

use App\Core\Audit\Auditor;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Manufacturing\CustomFields\BomEntity;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomLine;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Support\Errors;
use Modules\Manufacturing\Support\Qty;
use Modules\Manufacturing\Support\Units;

/**
 * MFG-02 to MFG-05: BOMs and their versions.
 *
 * - create(): a company's BOM of an item and kind (a production BOM makes
 *   a stock item; a recipe a `kit` or `non_stock` item, decision 3), one
 *   active per company, item and kind, with its first draft version.
 * - saveVersion(): a draft's output, labour and lines replaced (audited
 *   with the lines before and after); a published version is fixed.
 * - newVersion(): a new draft, empty or a copy of a version.
 * - publish(): the draft becomes the version in force from its date,
 *   strictly after the last published version's; its explosion is checked
 *   at that date (no cycle, at most 10 levels, every exploded component
 *   has a version in force).
 */
class Boms
{
    /** MFG-02: the item types a recipe's menu item may have (made to order, never stocked). */
    public const RECIPE_TYPES = ['kit', 'non_stock'];

    public function __construct(
        private readonly BomLines $lines,
        private readonly Units $units,
        private readonly Explosion $explosion,
        private readonly CustomFieldWriter $customFields,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): Bom
    {
        $company = Company::query()->findOrFail($data['company_id']);
        $item = $this->outputItem((string) $data['item_id'], $company->id, (string) $data['kind'], $by);
        $version = $this->versionInput($data['version'] ?? [], $item, $company->id, $by, 'version.');

        return Errors::transaction(function () use ($data, $by, $company, $item, $version) {
            Errors::lock("manufacturing:bom:{$company->id}:{$item->id}:{$data['kind']}");

            if (Bom::query()->where('company_id', $company->id)->where('item_id', $item->id)->where('kind', $data['kind'])->whereNull('archived_at')->exists()) {
                throw Errors::make(422, 'bom_exists', ['item' => $item->name], 'item_id');
            }

            $bom = new Bom([
                'company_id' => $company->id, 'item_id' => $item->id, 'kind' => $data['kind'],
                'name' => $data['name'] ?? null, 'created_by' => $by->id,
            ]);
            $this->customFields->fill(BomEntity::KEY, $bom, $data['custom'] ?? null, creating: true);

            try {
                $bom->save();
            } catch (UniqueConstraintViolationException) {
                throw Errors::make(422, 'bom_exists', ['item' => $item->name], 'item_id');
            }

            $this->customFields->saved(BomEntity::KEY, $bom);
            $draft = BomVersion::create([
                'bom_id' => $bom->id, 'version_no' => 1, 'status' => BomVersion::DRAFT, 'created_by' => $by->id,
                ...$version['attributes'],
            ]);
            $this->writeLines($draft, $version['lines']);

            return $bom->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Bom $bom, array $data): Bom
    {
        return Errors::transaction(function () use ($bom, $data) {
            $bom = Bom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('name', $data)) {
                $bom->name = $data['name'];
            }

            $this->customFields->fill(BomEntity::KEY, $bom, $data['custom'] ?? null, creating: false);
            $bom->save();
            $this->customFields->saved(BomEntity::KEY, $bom);

            return $bom->refresh();
        });
    }

    public function archive(Bom $bom): Bom
    {
        return Errors::transaction(function () use ($bom) {
            $bom = Bom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();

            if ($bom->archived_at === null) {
                $bom->archive();
            }

            return $bom->refresh();
        });
    }

    /** MFG-03: a new draft, a copy of $copyFrom (a version of this BOM) or of the latest version. */
    public function newVersion(Bom $bom, ?string $copyFrom, User $by): BomVersion
    {
        return Errors::transaction(function () use ($bom, $copyFrom, $by) {
            $bom = Bom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();

            if ($bom->archived_at !== null) {
                throw Errors::make(409, 'bom_archived');
            }

            if (BomVersion::query()->where('bom_id', $bom->id)->where('status', BomVersion::DRAFT)->exists()) {
                throw Errors::make(409, 'draft_exists');
            }

            $source = $copyFrom === null
                ? BomVersion::query()->where('bom_id', $bom->id)->orderByDesc('version_no')->first()
                : BomVersion::query()->where('bom_id', $bom->id)->whereKey($copyFrom)->first() ?? throw Errors::make(422, 'version_other_bom', field: 'copy_from_id');

            $draft = BomVersion::create([
                'bom_id' => $bom->id,
                'version_no' => (int) BomVersion::query()->where('bom_id', $bom->id)->max('version_no') + 1,
                'status' => BomVersion::DRAFT,
                'output_qty' => $source?->output_qty ?? '1',
                'output_uom_id' => $source?->output_uom_id ?? Item::query()->whereKey($bom->item_id)->value('base_uom_id'),
                'output_qty_base' => $source?->output_qty_base ?? '1',
                'labour_minutes' => $source?->labour_minutes ?? 0,
                'note' => $source?->note,
                'created_by' => $by->id,
            ]);

            foreach ($source?->lines ?? [] as $line) {
                BomLine::create([
                    ...collect($line->getAttributes())->except(['id', 'bom_version_id', 'import_id', 'tenant_id', 'created_at', 'updated_at'])->all(),
                    'bom_version_id' => $draft->id,
                ]);
            }

            return $draft->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function saveVersion(BomVersion $version, array $data, User $by): BomVersion
    {
        $bom = Bom::query()->findOrFail($version->bom_id);
        $item = Item::query()->findOrFail($bom->item_id);
        $input = $this->versionInput($data, $item, $bom->company_id, $by);

        return Errors::transaction(function () use ($version, $input) {
            $version = BomVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if (! $version->isDraft()) {
                throw Errors::make(409, 'version_published');
            }

            $before = $this->snapshot($version);
            $version->fill($input['attributes'])->save();
            BomLine::query()->where('bom_version_id', $version->id)->delete();
            $this->writeLines($version, $input['lines']);
            $this->auditor->record('manufacturing.bom_version.lines_update', $version, $before, $this->snapshot($version->refresh()));

            return $version;
        });
    }

    /** MFG-03, MFG-05: the draft in force from $effectiveFrom (default now). */
    public function publish(BomVersion $version, ?CarbonImmutable $effectiveFrom, User $by): BomVersion
    {
        return Errors::transaction(function () use ($version, $effectiveFrom, $by) {
            $bom = Bom::query()->whereKey($version->bom_id)->lockForUpdate()->firstOrFail();
            $version = BomVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if (! $version->isDraft()) {
                throw Errors::make(409, 'version_published');
            }

            if ($bom->archived_at !== null) {
                throw Errors::make(409, 'bom_archived');
            }

            if (! $version->lines()->where('kind', BomLine::COMPONENT)->exists()) {
                throw Errors::make(422, 'components_required');
            }

            $from = $effectiveFrom ?? CarbonImmutable::now();
            $last = BomVersion::query()->where('bom_id', $bom->id)->where('status', BomVersion::PUBLISHED)->max('effective_from');

            if ($last !== null && ! $from->greaterThan(CarbonImmutable::parse($last))) {
                throw Errors::make(422, 'effective_from_order', ['date' => CarbonImmutable::parse($last)->toIso8601String()], 'effective_from');
            }

            $version->setRelation('bom', $bom);
            $this->explosion->explode($version, BigDecimal::of((string) $version->output_qty_base), $from, [$bom->id => $version]);

            $version->fill(['status' => BomVersion::PUBLISHED, 'effective_from' => $from, 'published_by' => $by->id, 'published_at' => CarbonImmutable::now()])->save();

            return $version->refresh();
        });
    }

    /**
     * MFG-23: one imported line into the draft version of the company's BOM
     * of the item and kind (made with its first draft when missing; a new
     * draft after the published versions when none is open). The output
     * quantity, unit and labour of a draft are set from the row.
     *
     * @param  array<string, mixed>  $row  {company_id, item_id, kind, output_qty, output_uom_id?, labour_minutes?, line: {kind, item_id, uom_id?, qty, ...}}
     */
    public function importLine(array $row, User $by, ?string $importId = null): BomLine
    {
        $item = $this->outputItem((string) $row['item_id'], (string) $row['company_id'], (string) $row['kind'], $by);
        $version = $this->versionInput([
            'output_qty' => $row['output_qty'] ?? '1', 'output_uom_id' => $row['output_uom_id'] ?? null,
            'labour_minutes' => $row['labour_minutes'] ?? 0, 'lines' => [$row['line']],
        ], $item, (string) $row['company_id'], $by);

        return Errors::transaction(function () use ($row, $by, $item, $version, $importId) {
            Errors::lock('manufacturing:bom:'.$row['company_id'].':'.$item->id.':'.$row['kind']);
            $bom = Bom::query()->where('company_id', $row['company_id'])->where('item_id', $item->id)->where('kind', $row['kind'])->whereNull('archived_at')->first()
                ?? Bom::create(['company_id' => $row['company_id'], 'item_id' => $item->id, 'kind' => $row['kind'], 'created_by' => $by->id]);
            $draft = BomVersion::query()->where('bom_id', $bom->id)->where('status', BomVersion::DRAFT)->lockForUpdate()->first()
                ?? BomVersion::create([
                    'bom_id' => $bom->id, 'version_no' => (int) BomVersion::query()->where('bom_id', $bom->id)->max('version_no') + 1,
                    'status' => BomVersion::DRAFT, 'created_by' => $by->id, ...$version['attributes'],
                ]);
            $draft->fill($version['attributes'])->save();
            $line = $version['lines'][0];

            return BomLine::create([
                'bom_version_id' => $draft->id, 'line_no' => (int) BomLine::query()->where('bom_version_id', $draft->id)->max('line_no') + 1,
                'kind' => $line['kind'], 'item_id' => $line['item']->id, 'uom_id' => $line['uom_id'], 'qty' => (string) $line['qty'],
                'qty_base' => (string) $line['qty_base'], 'yield_percent' => $line['yield_percent'], 'wastage_percent' => $line['wastage_percent'],
                'explode' => $line['explode'], 'cost_share_percent' => $line['cost_share_percent'], 'note' => $line['note'], 'import_id' => $importId,
            ]);
        });
    }

    /** MFG-23: an imported line removed again, while its version is a draft (`bom_published` otherwise). */
    public function undoImportedLine(BomLine $line): void
    {
        Errors::transaction(function () use ($line) {
            $version = BomVersion::query()->whereKey($line->bom_version_id)->lockForUpdate()->firstOrFail();

            if (! $version->isDraft()) {
                throw Errors::make(409, 'bom_published');
            }

            $before = $this->snapshot($version);
            BomLine::query()->whereKey($line->id)->delete();
            $this->auditor->record('manufacturing.bom_version.lines_update', $version, $before, $this->snapshot($version));
        });
    }

    /** The output item of a new BOM: of the company or shared, of a type the kind allows. */
    private function outputItem(string $itemId, string $companyId, string $kind, User $by): Item
    {
        $item = Item::query()->whereKey($itemId)->whereNull('archived_at')->first();

        if ($item === null || ! $by->can('view', $item)) {
            throw Errors::make(422, 'item_unknown', field: 'item_id');
        }

        if ($item->company_id !== null && $item->company_id !== $companyId) {
            throw Errors::make(422, 'item_other_company', ['item' => $item->name], 'item_id');
        }

        if ($kind === Bom::PRODUCTION && $item->type !== 'stock') {
            throw Errors::make(422, 'not_a_stock_item', ['item' => $item->name], 'item_id');
        }

        if ($kind === Bom::RECIPE && ! in_array($item->type, self::RECIPE_TYPES, true)) {
            throw Errors::make(422, 'recipe_item_stocked', ['item' => $item->name], 'item_id');
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data  {output_qty, output_uom_id?, labour_minutes?, note?, lines}
     * @return array{attributes: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function versionInput(array $data, Item $item, string $companyId, User $by, string $prefix = ''): array
    {
        $uomId = (string) ($data['output_uom_id'] ?? $item->base_uom_id);
        $factor = $this->units->factor($item, $uomId) ?? throw Errors::make(422, 'uom_not_on_item', field: "{$prefix}output_uom_id");

        try {
            $qty = BigDecimal::of((string) ($data['output_qty'] ?? '1'));
        } catch (MathException) {
            throw Errors::make(422, 'qty_invalid', field: "{$prefix}output_qty");
        }

        if (! $qty->isPositive()) {
            throw Errors::make(422, 'qty_invalid', field: "{$prefix}output_qty");
        }

        if (! $this->units->fitsPrecision($uomId, $qty)) {
            throw Errors::make(422, 'qty_precision', field: "{$prefix}output_qty");
        }

        return [
            'attributes' => [
                'output_qty' => (string) Qty::of($qty), 'output_uom_id' => $uomId, 'output_qty_base' => (string) Qty::of($qty->multipliedBy($factor)),
                'labour_minutes' => (int) ($data['labour_minutes'] ?? 0), 'note' => $data['note'] ?? null,
            ],
            'lines' => $this->lines->resolve($data['lines'] ?? [], $companyId, $item->id, $by, "{$prefix}lines"),
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(BomVersion $version, array $lines): void
    {
        foreach ($lines as $index => $line) {
            BomLine::create([
                'bom_version_id' => $version->id, 'line_no' => $index + 1, 'kind' => $line['kind'], 'item_id' => $line['item']->id,
                'uom_id' => $line['uom_id'], 'qty' => (string) $line['qty'], 'qty_base' => (string) $line['qty_base'],
                'yield_percent' => $line['yield_percent'], 'wastage_percent' => $line['wastage_percent'], 'explode' => $line['explode'],
                'cost_share_percent' => $line['cost_share_percent'], 'note' => $line['note'],
            ]);
        }
    }

    /** @return array<string, mixed> what an audit entry of a version's lines keeps */
    private function snapshot(BomVersion $version): array
    {
        return [
            'output_qty' => Qty::show($version->output_qty), 'output_uom' => Uom::query()->whereKey($version->output_uom_id)->value('code'),
            'labour_minutes' => $version->labour_minutes,
            'lines' => BomLine::query()->where('bom_version_id', $version->id)->orderBy('line_no')->get()->map(fn (BomLine $line) => [
                'kind' => $line->kind, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty' => Qty::show($line->qty),
                'yield_percent' => Qty::show($line->yield_percent), 'wastage_percent' => Qty::show($line->wastage_percent),
                'explode' => $line->explode, 'cost_share_percent' => Qty::show($line->cost_share_percent),
            ])->all(),
        ];
    }
}
