<?php

namespace App\Core\Imports\Importers;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Imports\DomainRequests;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\ImportContext;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportRow;
use App\Core\Imports\PreparedRow;
use App\Core\Imports\RowRejected;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Prices\Http\Requests\ItemPriceActionRequest;
use App\Core\MasterData\Prices\Http\Requests\SetItemPriceRequest;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Prices\PriceWriter;
use App\Core\MasterData\Taxes\PriceList;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * IMP-01: item prices (MD-03 follow-up) by price list (name) and unit
 * (code; the item's base unit when blank), with an effective date
 * (today in the company's time zone when blank) and an optional quantity
 * break. Amounts are typed in major units of the list's currency and
 * stored in minor units (ADR 003). A price with the same list, item,
 * unit, date and break is the one updated in update mode. Each row passes
 * SetItemPriceRequest (`core.price.edit` at the list's company, prices not
 * frozen by field rules, RBAC-05) and is saved by PriceWriter.
 */
class ItemPricesImporter extends Importer
{
    public function key(): string
    {
        return 'item_prices';
    }

    public function model(): string
    {
        return ItemPrice::class;
    }

    public function permissions(string $mode): array
    {
        return ['core.price.edit'];
    }

    public function matchColumns(): array
    {
        return ['item_code', 'price_list', 'unit', 'effective_from', 'min_quantity'];
    }

    protected function baseColumns(): array
    {
        return [
            ImportColumn::text('item_code', true),
            ImportColumn::text('price_list', true),
            ImportColumn::text('unit'),
            ImportColumn::of('amount', 'decimal', true),
            ImportColumn::text('currency'),
            ImportColumn::of('effective_from', 'date'),
            ImportColumn::of('min_quantity', 'decimal'),
        ];
    }

    public function example(): array
    {
        return ['item_code' => 'SODA-500', 'price_list' => 'Retail', 'unit' => 'EA', 'amount' => '60.00', 'currency' => 'KES', 'effective_from' => '', 'min_quantity' => '1'];
    }

    protected function fieldColumns(): array
    {
        return [
            'item_id' => 'item_code', 'uom_id' => 'unit', 'amount_minor' => 'amount', 'currency' => 'currency',
            'effective_from' => 'effective_from', 'min_quantity' => 'min_quantity', 'prices' => 'amount',
        ];
    }

    public function identity(array $values): ?string
    {
        return Str::lower(implode('|', [$values['item_code'] ?? '', $values['price_list'] ?? '', $values['unit'] ?? '', $values['effective_from'] ?? '', $values['min_quantity'] ?? '1']));
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $errors = [];
        $lists = $context->remember('price_lists', fn () => PriceList::query()->whereNull('archived_at')->get()
            ->groupBy(fn (PriceList $list) => Str::lower($list->name))->all());
        $matches = $lists[Str::lower($values['price_list'] ?? '')] ?? collect();

        if ($matches->count() !== 1) {
            throw new RowRejected(['price_list' => [__($matches->isEmpty() ? 'imports.errors.unknown' : 'imports.errors.ambiguous', ['value' => $values['price_list'] ?? ''])]]);
        }

        /** @var PriceList $list */
        $list = $matches->first();
        // TEN-08: a shared item, or the list's company's.
        $item = Item::query()->whereNull('archived_at')->whereCode((string) ($values['item_code'] ?? ''))
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $list->company_id))->first();

        if ($item === null) {
            $errors['item_code'][] = __('imports.errors.unknown', ['value' => $values['item_code'] ?? '']);
        }

        $uomId = $item?->base_uom_id;

        if (array_key_exists('unit', $values)) {
            $uomId = $context->remember('units', fn () => Uom::query()->whereNull('archived_at')->get(['id', 'code'])
                ->mapWithKeys(fn (Uom $uom) => [Str::lower($uom->code) => $uom->id])->all())[Str::lower($values['unit'])] ?? null;

            if ($uomId === null) {
                $errors['unit'][] = __('imports.errors.unknown_unit', ['value' => $values['unit']]);
            }
        }

        // As PriceWriter checks when saving, so previews and dry runs say it too.
        if ($item !== null && $uomId !== null && $uomId !== $item->base_uom_id && ! ItemUom::query()->where('item_id', $item->id)->where('uom_id', $uomId)->exists()) {
            $errors['unit'][] = __('core.price.uom_not_on_item');
        }

        $currency = Str::upper($values['currency'] ?? $list->currency);

        if ($currency !== $list->currency) {
            $errors['currency'][] = __('core.price.currency_mismatch', ['currency' => $list->currency]);
        }

        try {
            $amount = Money::parse((string) $values['amount'], $currency, app(CurrencyDecimals::class))->minor();
        } catch (InvalidArgumentException) {
            $errors['amount'][] = __('imports.errors.amount', ['currency' => $currency]);
        }

        if ($errors !== []) {
            throw new RowRejected($errors);
        }

        $input = [
            'item_id' => $item->id,
            'uom_id' => $uomId,
            'amount_minor' => $amount,
            'currency' => $currency,
            'effective_from' => $values['effective_from'] ?? PriceWriter::today($list),
            'min_quantity' => $values['min_quantity'] ?? '1',
        ];

        $existing = ItemPrice::query()->whereNull('archived_at')
            ->where('price_list_id', $list->id)->where('item_id', $item->id)->where('uom_id', $uomId)
            ->where('effective_from', $input['effective_from'])
            ->where('min_quantity', self::quantity($input['min_quantity']))
            ->first();

        if ($existing !== null && ! $context->updates()) {
            throw new RowRejected(['amount' => [__('imports.errors.price_exists')]]);
        }

        return new PreparedRow($number, $input, $existing, ['list' => $list, 'item' => $item]);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        $row->validated = app(DomainRequests::class)->validate(SetItemPriceRequest::class, $row->input, $context->user, ['price_list' => $row->context['list']]);
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        $before = $row->record === null ? null : ['amount_minor' => (string) ItemPrice::query()->whereKey($row->record->getKey())->value('amount_minor')];
        [$result] = app(PriceWriter::class)->write($row->context['list'], [$row->validated]);

        return ['record' => $result['price'], 'before' => $result['created'] ? null : $before];
    }

    /**
     * Created: archived by PriceWriter, as POST item-prices/{item_price}/archive
     * (`core.price.edit` at the list's company, prices not frozen, RBAC-05).
     * Updated: the amount before is set again through SetItemPriceRequest and
     * PriceWriter, as the undoer.
     */
    public function undo(ImportRow $row, ImportContext $context): void
    {
        $price = ItemPrice::query()->whereKey($row->record_id)->first();

        if ($price === null || $price->isArchived()) {
            return;
        }

        $requests = app(DomainRequests::class);
        $writer = app(PriceWriter::class);

        if ($row->action === 'created') {
            $requests->validate(ItemPriceActionRequest::class, [], $context->user, ['item_price' => $price]);
            $writer->archive($price);

            return;
        }

        $amount = (string) ($row->before['amount_minor'] ?? $price->amount_minor);

        if ($amount === (string) $price->amount_minor) {
            return;
        }

        $list = PriceList::query()->findOrFail($price->price_list_id);
        $validated = $requests->validate(SetItemPriceRequest::class, [
            'item_id' => $price->item_id,
            'uom_id' => $price->uom_id,
            'amount_minor' => $amount,
            'currency' => $price->currency,
            'effective_from' => $price->effective_from->toDateString(),
            'min_quantity' => $price->minQuantity(),
        ], $context->user, ['price_list' => $list]);
        $writer->write($list, [$validated]);
    }

    /**
     * The price lists a batch may write, locked in id order before the audit
     * head, as PriceWriter locks its list before saving (and auditing).
     */
    public function lockForBatch(): void
    {
        PriceList::query()->whereNull('archived_at')->orderBy('id')->lockForUpdate()->pluck('id');
    }

    public function companiesOf(array $ids): array
    {
        return PriceList::query()->whereIn('id', ItemPrice::query()->whereIn('id', $ids)->select('price_list_id'))->distinct()->pluck('company_id')->all();
    }

    public function readPermissions(): array
    {
        return ['core.price.view', 'core.price.edit'];
    }

    public function describe(Model $record): string
    {
        $item = Item::query()->whereKey($record->item_id)->first();
        $list = PriceList::query()->whereKey($record->price_list_id)->value('name');

        return trim(($item?->code ?? '').' '.($list ?? '').' '.$record->effective_from?->toDateString());
    }

    private static function quantity(string $value): string
    {
        try {
            return (string) BigDecimal::of($value)->toScale(6);
        } catch (Throwable) {
            return '1.000000';
        }
    }
}
