<?php

namespace Modules\Manufacturing\Imports;

use App\Core\Identity\Models\User;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\ImportContext;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportRow;
use App\Core\Imports\PreparedRow;
use App\Core\Imports\RowRejected;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Manufacturing\Boms\Boms;
use Modules\Manufacturing\ManufacturingServiceProvider;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomLine;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Support\Places;
use Throwable;

/**
 * IMP-01, IMP-02, MFG-23: BOM lines from a file, one row per line: the
 * company, the output item code, the BOM's kind, the batch's output
 * quantity and unit and its labour minutes, then the line: kind
 * (component or by-product), component item code, quantity and unit,
 * yield, wastage, explode, cost share. Each row goes into the draft
 * version of that item's BOM (made when missing) and passes the same
 * checks as the BOM editor (`manufacturing.bom.manage` in the company);
 * publishing stays a person's act. Create mode only.
 *
 * Undo (IMP-02) removes the line while its version is still a draft
 * (`bom_published` once published). Runnable only while Manufacturing is
 * active (RBAC-08).
 */
class BomLinesImporter extends Importer
{
    public const KEY = 'bom_lines';

    public function key(): string
    {
        return self::KEY;
    }

    public function model(): string
    {
        return BomLine::class;
    }

    public function permissions(string $mode): array
    {
        return ['manufacturing.bom.manage'];
    }

    public function canRun(User $user, string $mode = 'create'): bool
    {
        return $mode === 'create' && app(ModuleRegistry::class)->isActive(ManufacturingServiceProvider::MODULE) && parent::canRun($user, $mode);
    }

    public function matchColumns(): array
    {
        return [];
    }

    protected function baseColumns(): array
    {
        return [
            ImportColumn::text('company', true),
            ImportColumn::text('item_code', true),
            ImportColumn::enum('bom_kind', Bom::KINDS, 'manufacturing.kinds', true),
            ImportColumn::of('output_qty', 'decimal'),
            ImportColumn::text('output_unit'),
            ImportColumn::of('labour_minutes', 'integer'),
            ImportColumn::enum('line_kind', BomLine::KINDS, 'manufacturing.line_kinds'),
            ImportColumn::text('component_code', true),
            ImportColumn::of('quantity', 'decimal', true),
            ImportColumn::text('unit'),
            ImportColumn::of('yield_percent', 'decimal'),
            ImportColumn::of('wastage_percent', 'decimal'),
            ImportColumn::of('explode', 'boolean'),
            ImportColumn::of('cost_share_percent', 'decimal'),
        ];
    }

    public function example(): array
    {
        return [
            'company' => 'Acme', 'item_code' => 'BREAD-800', 'bom_kind' => 'production', 'output_qty' => '100', 'output_unit' => 'EA',
            'labour_minutes' => '90', 'line_kind' => 'component', 'component_code' => 'FLOUR', 'quantity' => '50', 'unit' => 'KG',
            'yield_percent' => '100', 'wastage_percent' => '2', 'explode' => 'no', 'cost_share_percent' => '',
        ];
    }

    protected function fieldColumns(): array
    {
        return [
            'company_id' => 'company', 'item_id' => 'item_code', 'kind' => 'bom_kind', 'output_qty' => 'output_qty', 'output_uom_id' => 'output_unit',
            'labour_minutes' => 'labour_minutes', 'lines.0.kind' => 'line_kind', 'lines.0.item_id' => 'component_code', 'lines.0.qty' => 'quantity',
            'lines.0.uom_id' => 'unit', 'lines.0.yield_percent' => 'yield_percent', 'lines.0.wastage_percent' => 'wastage_percent',
            'lines.0.explode' => 'explode', 'lines.0.cost_share_percent' => 'cost_share_percent',
        ];
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $errors = [];
        $companies = $context->remember('companies', fn () => Company::query()->get(['id', 'name']));
        $wanted = Str::lower(trim((string) ($values['company'] ?? '')));
        $matches = $companies->filter(fn (Company $company) => Str::lower($company->name) === $wanted);

        if ($matches->count() !== 1) {
            throw new RowRejected(['company' => [__($matches->isEmpty() ? 'imports.errors.unknown' : 'imports.errors.ambiguous', ['value' => $values['company'] ?? ''])]]);
        }

        $company = $matches->first();
        $item = fn (string $code) => Item::query()->whereNull('archived_at')->whereCode($code)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->first();
        $units = $context->remember('units', fn () => Uom::query()->whereNull('archived_at')->get(['id', 'code'])
            ->mapWithKeys(fn (Uom $uom) => [Str::lower($uom->code) => $uom->id])->all());
        $output = $item((string) ($values['item_code'] ?? ''));
        $component = $item((string) ($values['component_code'] ?? ''));

        if ($output === null) {
            $errors['item_code'][] = __('imports.errors.unknown', ['value' => $values['item_code'] ?? '']);
        }

        if ($component === null) {
            $errors['component_code'][] = __('imports.errors.unknown', ['value' => $values['component_code'] ?? '']);
        }

        $unit = function (string $column) use ($values, $units, &$errors): ?string {
            if (($values[$column] ?? '') === '') {
                return null;
            }

            $id = $units[Str::lower((string) $values[$column])] ?? null;

            if ($id === null) {
                $errors[$column][] = __('imports.errors.unknown_unit', ['value' => $values[$column]]);
            }

            return $id;
        };
        $outputUom = $unit('output_unit');
        $uom = $unit('unit');

        if ($errors !== []) {
            throw new RowRejected($errors);
        }

        return new PreparedRow($number, [
            'company_id' => $company->id,
            'item_id' => $output->id,
            'kind' => (string) $values['bom_kind'],
            'output_qty' => (string) ($values['output_qty'] ?? '') === '' ? '1' : (string) $values['output_qty'],
            'output_uom_id' => $outputUom,
            'labour_minutes' => (int) ($values['labour_minutes'] ?? 0),
            'line' => array_filter([
                'kind' => ($values['line_kind'] ?? '') === '' ? BomLine::COMPONENT : (string) $values['line_kind'],
                'item_id' => $component->id,
                'uom_id' => $uom,
                'qty' => (string) $values['quantity'],
                'yield_percent' => ($values['yield_percent'] ?? '') === '' ? null : (string) $values['yield_percent'],
                'wastage_percent' => ($values['wastage_percent'] ?? '') === '' ? null : (string) $values['wastage_percent'],
                'explode' => (bool) ($values['explode'] ?? false),
                'cost_share_percent' => ($values['cost_share_percent'] ?? '') === '' ? null : (string) $values['cost_share_percent'],
            ], fn ($v) => $v !== null),
        ], null, ['company' => $company]);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        if (! app(Places::class)->inCompany($context->user, 'manufacturing.bom.manage', (string) $row->input['company_id'])) {
            throw RowRejected::row(__('imports.errors.forbidden'));
        }

        $row->validated = $row->input;
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        try {
            return ['record' => app(Boms::class)->importLine($row->validated, $context->user), 'before' => null];
        } catch (Throwable $e) {
            throw RowRejected::from($e) ?? $e;
        }
    }

    public function undo(ImportRow $row, ImportContext $context): void
    {
        $line = BomLine::query()->find($row->record_id);

        if ($line === null) {
            return;
        }

        $companyId = (string) Bom::query()->whereKey(BomVersion::query()->whereKey($line->bom_version_id)->value('bom_id'))->value('company_id');

        if (! app(Places::class)->inCompany($context->user, 'manufacturing.bom.manage', $companyId)) {
            throw RowRejected::row(__('imports.errors.forbidden'));
        }

        try {
            app(Boms::class)->undoImportedLine($line);
        } catch (Throwable $e) {
            throw RowRejected::from($e) ?? $e;
        }
    }

    public function companiesOf(array $ids): array
    {
        return Bom::query()->whereIn('id', BomVersion::query()->select('bom_id')->whereIn('id', BomLine::query()->select('bom_version_id')->whereIn('id', $ids)))
            ->distinct()->pluck('company_id')->all();
    }

    public function readPermissions(): array
    {
        return ['manufacturing.bom.view', 'manufacturing.bom.manage'];
    }

    public function describe(Model $record): string
    {
        $version = BomVersion::query()->find($record->getAttribute('bom_version_id'));
        $output = $version === null ? null : Item::query()->whereKey(Bom::query()->whereKey($version->bom_id)->value('item_id'))->value('code');
        $component = Item::query()->whereKey($record->getAttribute('item_id'))->value('code');

        return trim($output.' ← '.$component);
    }

    public function label(): string
    {
        return __('imports.importers.bom_lines');
    }
}
