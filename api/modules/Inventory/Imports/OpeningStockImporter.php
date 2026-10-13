<?php

namespace Modules\Inventory\Imports;

use App\Core\Identity\Models\User;
use App\Core\Imports\DomainRequests;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\ImportContext;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportRow;
use App\Core\Imports\PreparedRow;
use App\Core\Imports\RowRejected;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Inventory\Http\Requests\StoreOpeningStockRequest;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\OpeningStock;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Qty;
use Throwable;

/**
 * IMP-01, INV-18: opening stock from a file, one row per item and
 * location: item code, location (code, else name), quantity, unit (the
 * item's base unit when blank), unit cost (major units of the company's
 * base currency), batch number and expiry, serial numbers. Each row passes
 * StoreOpeningStockRequest (`inventory.opening.create` at the location)
 * and the stock checks, and is posted as an `opening` movement, the import's
 * record. Create mode only (stock is never "updated").
 *
 * Undo (IMP-02): each movement is reversed exactly, refused once anything
 * later moved the item in the company (RecordUsage `inventory_opening`).
 * Runnable only while Inventory is active (RBAC-08).
 */
class OpeningStockImporter extends Importer
{
    public const KEY = 'opening_stock';

    public function key(): string
    {
        return self::KEY;
    }

    public function model(): string
    {
        return Movement::class;
    }

    public function permissions(string $mode): array
    {
        return ['inventory.opening.create'];
    }

    public function canRun(User $user, string $mode = 'create'): bool
    {
        return $mode === 'create' && app(ModuleRegistry::class)->isActive(InventoryServiceProvider::MODULE) && parent::canRun($user, $mode);
    }

    public function matchColumns(): array
    {
        return [];
    }

    protected function baseColumns(): array
    {
        return [
            ImportColumn::text('item_code', true),
            ImportColumn::text('location', true),
            ImportColumn::of('quantity', 'decimal', true),
            ImportColumn::text('unit'),
            ImportColumn::of('unit_cost', 'decimal', true),
            ImportColumn::text('batch_number'),
            ImportColumn::of('expires_on', 'date'),
            ImportColumn::of('serials', 'list'),
        ];
    }

    public function example(): array
    {
        return ['item_code' => 'SODA-500', 'location' => 'L01', 'quantity' => '24', 'unit' => 'EA', 'unit_cost' => '45.00', 'batch_number' => '', 'expires_on' => '', 'serials' => ''];
    }

    protected function fieldColumns(): array
    {
        return [
            'item_id' => 'item_code', 'location_id' => 'location', 'qty' => 'quantity', 'uom_id' => 'unit', 'unit_cost' => 'unit_cost',
            'batch_number' => 'batch_number', 'batch_id' => 'batch_number', 'expires_on' => 'expires_on', 'serials' => 'serials',
        ];
    }

    public function identity(array $values): ?string
    {
        return Str::lower(($values['item_code'] ?? '').'|'.($values['location'] ?? '').'|'.($values['batch_number'] ?? ''));
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $errors = [];
        $locations = $context->remember('locations', fn () => Location::query()->whereNull('archived_at')->get(['id', 'code', 'name']));
        $wanted = Str::lower((string) ($values['location'] ?? ''));
        $byCode = $locations->filter(fn (Location $l) => $l->code !== null && Str::lower((string) $l->code) === $wanted);
        $matches = $byCode->isNotEmpty() ? $byCode : $locations->filter(fn (Location $l) => Str::lower($l->name) === $wanted);

        if ($matches->count() !== 1) {
            throw new RowRejected(['location' => [__($matches->isEmpty() ? 'imports.errors.unknown' : 'imports.errors.ambiguous', ['value' => $values['location'] ?? ''])]]);
        }

        $location = $matches->first();
        $companyId = app(Places::class)->of($location->id)['company_id'] ?? null;
        $item = Item::query()->whereNull('archived_at')->whereCode((string) ($values['item_code'] ?? ''))
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->first();

        if ($item === null) {
            $errors['item_code'][] = __('imports.errors.unknown', ['value' => $values['item_code'] ?? '']);
        }

        $uomId = $item?->base_uom_id;

        if (($values['unit'] ?? '') !== '') {
            $uomId = $context->remember('units', fn () => Uom::query()->whereNull('archived_at')->get(['id', 'code'])
                ->mapWithKeys(fn (Uom $uom) => [Str::lower($uom->code) => $uom->id])->all())[Str::lower((string) $values['unit'])] ?? null;

            if ($uomId === null) {
                $errors['unit'][] = __('imports.errors.unknown_unit', ['value' => $values['unit']]);
            }
        }

        if ($errors !== []) {
            throw new RowRejected($errors);
        }

        return new PreparedRow($number, array_filter([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'uom_id' => $uomId,
            'qty' => (string) $values['quantity'],
            'unit_cost' => (string) $values['unit_cost'],
            'batch_number' => $values['batch_number'] ?? null,
            'expires_on' => $values['expires_on'] ?? null,
            'serials' => $values['serials'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''), null, ['location' => $location]);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        $row->validated = app(DomainRequests::class)->validate(StoreOpeningStockRequest::class, $row->input, $context->user);
    }

    /** The stock checks the posting makes (unit, batch, serials), so previews and dry runs say them too. */
    public function check(PreparedRow $row, ImportContext $context): void
    {
        try {
            app(OpeningStock::class)->check($row->validated, $context->user);
        } catch (Throwable $e) {
            throw RowRejected::from($e) ?? $e;
        }
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        try {
            return ['record' => app(OpeningStock::class)->record($row->validated, $context->user), 'before' => null];
        } catch (Throwable $e) {
            throw RowRejected::from($e) ?? $e;
        }
    }

    public function undo(ImportRow $row, ImportContext $context): void
    {
        $movement = Movement::query()->find($row->record_id);

        if ($movement === null) {
            return;
        }

        if ($movement->location_id === null || ! app(ScopeResolver::class)->can($context->user, 'inventory.opening.create', Scope::location($movement->location_id))) {
            throw RowRejected::row(__('imports.errors.forbidden'));
        }

        try {
            app(OpeningStock::class)->reverse($movement, $context->user);
        } catch (Throwable $e) {
            throw RowRejected::from($e) ?? $e;
        }
    }

    public function usageEntity(): ?string
    {
        return 'inventory_opening';
    }

    public function companiesOf(array $ids): array
    {
        return Movement::query()->whereIn('id', $ids)->distinct()->pluck('company_id')->all();
    }

    public function readPermissions(): array
    {
        return ['inventory.stock.view', 'inventory.opening.create'];
    }

    public function describe(Model $record): string
    {
        $item = Item::query()->whereKey($record->getAttribute('item_id'))->first();

        return trim(($item?->code ?? '').' '.Qty::show((string) $record->getAttribute('qty')));
    }

    public function label(): string
    {
        return __('imports.importers.opening_stock');
    }
}
