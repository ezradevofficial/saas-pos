<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\Money;
use App\Core\Currency\Rate;
use App\Core\Currency\RateUnavailable;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCalculator;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRateMissing;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SalePayment;
use Modules\POS\Models\Shift;

/**
 * POS-01, POS-03, POS-07, POS-09, POS-11: completed sales uploaded by a
 * till (ADR 004).
 *
 * - **Idempotent.** A sale whose id the device already uploaded answers
 *   the stored result, unchanged; nothing is stored or raised twice. Each
 *   sale has its own transaction: one refused sale never blocks the rest.
 * - **Checked against the device.** The shift and receipt range must be
 *   this device's; place columns come from the device, never the body;
 *   every id is read under the tenant (another tenant's id is unknown).
 * - **Device wins.** Prices, discounts, tax and rates are kept as sold.
 *   What the server would have done differently is recorded in `flags`
 *   (tax_differs, rate_differs, discount_unauthorised,
 *   price_override_unauthorised, cashier_not_permitted, received_after_close,
 *   override_unverified, actor_unverified, change_rate_differs,
 *   list_price_missing, price_unknown, price_differs, clock_ahead), never
 *   refused. Money in is never held:
 *   a cashier or override the server can't prove is flagged.
 * - **Same id, other content** (`payload_mismatch`): refused, the stored
 *   sale stays as it was.
 * - **A closed shift** gets its expected cash recounted (H4).
 * - **A shift the server never received** (NFR-04): the sale waits
 *   (`shift_unknown`, retryable) for `pos.unknown_shift_grace_hours`
 *   after it was sold, then is stored on a placeholder shift with the
 *   device's shift id (closed, flagged `placeholder`) and flagged
 *   `shift_missing` for review, so money taken never retries for ever.
 * - **Refused** (the sale is not a sale the server can keep): unknown or
 *   foreign references, sums that do not add up, a tender conversion the
 *   stated rate does not give, change above the overpayment, and an item
 *   whose tax rate is "Rate needed" or that has no tax code (rates are
 *   never invented).
 * - **Base amounts** (CUR-04): the company's base is locked by the first
 *   sale; each line converts at the server's rate in force at the sale's
 *   time, else at the till's rate for that pair, else the sale waits
 *   (`rate_unavailable`, retryable).
 */
class SaleUploads
{
    /** A clock this far ahead of the server is flagged. */
    private const CLOCK_TOLERANCE_MINUTES = 5;

    public function __construct(
        private readonly Authority $authority,
        private readonly NumberRanges $ranges,
        private readonly Sellability $sellability,
        private readonly TaxCalculator $taxes,
        private readonly ExchangeRates $rates,
        private readonly BaseCurrencyLock $baseLock,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
        private readonly ShiftCash $cash,
        private readonly PriceResolver $prices,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $sales
     * @return list<array<string, mixed>> one result per sale, in order
     */
    public function upload(DevicePlace $place, array $sales): array
    {
        return array_map(fn (array $sale) => $this->one($place, $sale), $sales);
    }

    /** @return array<string, mixed> */
    private function one(DevicePlace $place, array $data): array
    {
        $existing = $this->stored($place, $data['id'], Records::hash($data));

        if ($existing !== null) {
            return $existing;
        }

        try {
            $sale = DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($place, $data));
        } catch (Rejection $rejection) {
            return UploadResults::rejected($data['id'], $rejection);
        } catch (UniqueConstraintViolationException $e) {
            // The same sale stored meanwhile (a concurrent resend), a receipt number used twice, or an id taken.
            return $this->stored($place, $data['id'], Records::hash($data))
                ?? UploadResults::rejected($data['id'], new Rejection(NumberRanges::isReuse($e) ? 'receipt_number_used' : 'id_conflict', NumberRanges::isReuse($e) ? 'receipt_seq' : 'id'));
        }

        return UploadResults::sale($sale);
    }

    /**
     * The stored result when this device already uploaded $id with the same
     * content; a rejection when the id is another device's or the content
     * differs.
     */
    private function stored(DevicePlace $place, string $id, string $hash): ?array
    {
        $sale = Sale::query()->find($id);

        return match (true) {
            $sale === null => null,
            $sale->device_id !== $place->device->id => UploadResults::rejected($id, new Rejection('id_conflict', 'id')),
            $sale->payload_hash !== $hash => UploadResults::rejected($id, new Rejection('payload_mismatch', 'id')),
            default => UploadResults::sale($sale),
        };
    }

    private function store(DevicePlace $place, array $data): Sale
    {
        $at = CarbonImmutable::parse($data['sold_at'])->utc();
        $company = $place->company;
        $currency = $data['currency'];
        $flags = new Flags;

        if ($at->greaterThan(now()->addMinutes(self::CLOCK_TOLERANCE_MINUTES))) {
            $flags->add('clock_ahead');
        }

        $shift = $this->shift($place, $data['shift_id'], $data['cashier_id'], $at, $flags);
        $cashier = $this->authority->user($data['cashier_id'], 'cashier_id');

        if (! $this->authority->can($cashier, 'pos.sale.create', $place->scope())) {
            $flags->add('cashier_not_permitted');
        }

        // H3, AUTH-07: money in is kept; a cashier the till can't prove (no verified sign-in attestation) is flagged.
        if ($this->authority->proven($place->device, $cashier, $data['actor_proof'] ?? null) === null) {
            $flags->add('actor_unverified');
        }

        foreach ($this->authority->sessionFlags($place->device, $cashier, $data['actor_proof'] ?? null, $at) as $code) {
            $flags->add($code);
        }

        $this->customer($place, $data['customer_id'] ?? null);

        if (! TenantCurrency::query()->where('code', $currency)->exists()) {
            throw new Rejection('currency_unknown', 'currency');
        }

        $saleList = $this->priceList($place, $data['price_list_id'] ?? null, $currency, 'price_list_id');
        $lines = [];

        foreach ($data['lines'] as $index => $line) {
            // AUTH-07: a line without its own attestation is covered by the sale's (same cashier, same sign-in).
            $line['actor_proof'] ??= $data['actor_proof'] ?? null;
            $lines[] = $this->line($place, $line, $index, $currency, $at, $cashier, $flags, $data['id']);
        }

        $totals = $this->totals($lines, $data['totals']);
        $total = Money::ofMinor((string) $totals['total_minor'], $currency);
        [$payments, $paid, $paidExact] = $this->payments($place, $data['payments'], $currency, $at, $flags);

        if ($paid->isLessThan($totals['total_minor'])) {
            throw new Rejection('sale_underpaid', 'payments');
        }

        [$change, $rounding, $changeSnapshot] = $this->change($data['change'] ?? null, $currency, $paidExact->minus($totals['total_minor']));

        // H1: money out at a rate the server doesn't hold is flagged.
        if ($changeSnapshot !== null && ! $change->isZero() && ! $this->matchesServerRate($place, $changeSnapshot, $at)) {
            $flags->add('change_rate_differs', null, ['pair' => "{$changeSnapshot->base}/{$changeSnapshot->quote}"]);
        }
        [$base, $snapshot, $baseTotal, $baseTax] = $this->base($place, $lines, $currency, $at, [...array_column($payments, 'fx_snapshot'), $changeSnapshot], $flags);

        $range = $this->ranges->claim($place, 'pos.receipt', (int) $data['receipt_seq'], $data['receipt_number'], $at, 'receipt', $data['number_range_id'] ?? null);

        $sale = Sale::create([
            'id' => $data['id'],
            ...$place->columns(),
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'customer_id' => $data['customer_id'] ?? null,
            'number_range_id' => $range->id,
            'receipt_seq' => (int) $data['receipt_seq'],
            'receipt_number' => $data['receipt_number'],
            'status' => Sale::COMPLETED,
            'currency' => $currency,
            'price_list_id' => $saleList?->id,
            'subtotal_minor' => $totals['subtotal_minor'],
            'discount_minor' => $totals['discount_minor'],
            'tax_minor' => $totals['tax_minor'],
            'total_minor' => $totals['total_minor'],
            'paid_minor' => (string) $paid,
            'change_minor' => $change->minor(),
            'change_currency' => $change->currency(),
            'rounding_minor' => (string) $rounding,
            'base_currency' => $base,
            'base_total_minor' => $baseTotal,
            'base_tax_minor' => $baseTax,
            'fx' => $snapshot,
            'sold_at' => $at,
            'received_at' => now(),
            'offline' => (bool) ($data['offline'] ?? false),
            'flags' => $flags->all(),
            'payload_hash' => Records::hash($data),
        ]);

        foreach ($lines as $line) {
            SaleLine::create(['sale_id' => $sale->id, ...$line['row']]);
        }

        foreach ($payments as $payment) {
            SalePayment::create(['sale_id' => $sale->id, ...$payment['row']]);
        }

        $this->audit($sale, $lines, $cashier, $at, $total);
        $this->cash->recountIfClosed($shift->id, 'sale', $sale->id);
        SaleCompleted::dispatch($this->tenants->require(), $sale->id, $sale->company_id, $sale->location_id);

        return $sale;
    }

    private function shift(DevicePlace $place, string $id, string $cashierId, CarbonImmutable $at, Flags $flags): Shift
    {
        $shift = Shift::query()->find($id);

        if ($shift === null) {
            // Not uploaded yet (the device sends shifts first): retry later, within the grace.
            if ($at->greaterThan(now()->subHours((int) config('pos.unknown_shift_grace_hours')))) {
                throw new Rejection('shift_unknown', 'shift_id', retryable: true);
            }

            $shift = $this->placeholder($place, $id, $cashierId, $at);
        }

        if ($shift->device_id !== $place->device->id) {
            throw new Rejection('shift_other_device', 'shift_id');
        }

        if (in_array('placeholder', array_column($shift->flags ?? [], 'code'), true)) {
            $flags->add('shift_missing');
        } elseif (! $shift->isOpen()) {
            $flags->add('received_after_close');
        }

        return $shift;
    }

    /**
     * NFR-04: a closed shift standing in for one the device never got onto
     * the server, with the device's id (a later upload of the real shift is
     * answered as stored; its float and count are left to the review).
     * Made once: a sale racing for it, or an id another tenant holds, finds
     * what is there.
     */
    private function placeholder(DevicePlace $place, string $id, string $cashierId, CarbonImmutable $at): Shift
    {
        $cashier = $this->authority->user($cashierId, 'cashier_id');

        $created = Shift::query()->insertOrIgnore([
            'id' => $id,
            'tenant_id' => $this->tenants->require(),
            ...$place->columns(),
            'status' => Shift::CLOSED,
            'opened_by' => $cashier->id,
            'opened_at' => $at,
            'closed_by' => $cashier->id,
            'closed_at' => $at,
            'received_at' => now(),
            'closed_received_at' => now(),
            'flags' => json_encode([['code' => 'placeholder']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shift = Shift::query()->whereKey($id)->lockForUpdate()->first() ?? throw new Rejection('shift_other_device', 'shift_id');

        if ($created === 1) {
            $this->auditor->record('pos.shift.placeholder', $shift, null, ['status' => Shift::CLOSED, 'flags' => $shift->flags], ['user_id' => $cashier->id, 'device_time' => $at]);
        }

        return $shift;
    }

    /** POS-08: a customer of the sale's company (shared or its own). */
    private function customer(DevicePlace $place, ?string $id): void
    {
        if ($id === null) {
            return;
        }

        $party = Party::query()->find($id);

        if ($party === null || ! in_array('customer', (array) $party->roles, true)
            || ($party->company_id !== null && $party->company_id !== $place->company->id)) {
            throw new Rejection('customer_unknown', 'customer_id');
        }
    }

    private function priceList(DevicePlace $place, ?string $id, string $currency, string $field): ?PriceList
    {
        if ($id === null) {
            return null;
        }

        $list = PriceList::query()->find($id);

        if ($list === null || $list->company_id !== $place->company->id) {
            throw new Rejection('price_list_unknown', $field);
        }

        if ($list->currency !== $currency) {
            throw new Rejection('price_list_currency', $field);
        }

        return $list;
    }

    /** @return array{row: array<string, mixed>, gross: BigDecimal, discount: BigDecimal, tax: BigDecimal, total: BigDecimal, item: Item, override: ?Approval, price_override: ?Approval, percent: ?BigDecimal} */
    private function line(DevicePlace $place, array $line, int $index, string $currency, CarbonImmutable $at, User $cashier, Flags $flags, string $saleId): array
    {
        $field = "lines.{$index}";
        $company = $place->company;
        $item = Item::query()->find($line['item_id']);

        if ($item === null || ($item->company_id !== null && $item->company_id !== $company->id)) {
            throw new Rejection('item_unknown', "{$field}.item_id");
        }

        if ($line['uom_id'] !== $item->base_uom_id && ! ItemUom::query()->where('item_id', $item->id)->where('uom_id', $line['uom_id'])->exists()) {
            throw new Rejection('uom_unknown', "{$field}.uom_id");
        }

        $list = $this->priceList($place, $line['price_list_id'] ?? null, $currency, "{$field}.price_list_id");
        $inclusive = (bool) $line['tax_inclusive'];

        if ($list !== null && $list->tax_inclusive !== $inclusive) {
            throw new Rejection('price_list_tax_mismatch', "{$field}.tax_inclusive");
        }

        $gross = Amounts::extend($line['unit_price_minor'], (string) $line['qty']);
        $discount = BigDecimal::of((string) $line['discount_minor']);

        if ($discount->isGreaterThan($gross)) {
            throw new Rejection('discount_above_price', "{$field}.discount_minor");
        }

        $amount = $gross->minus($discount);
        $tax = BigDecimal::of((string) $line['tax_minor']);
        $total = BigDecimal::of((string) $line['total_minor']);

        if (! $total->isEqualTo($inclusive ? $amount : $amount->plus($tax)) || ($inclusive && $tax->isGreaterThan($amount))) {
            throw new Rejection('line_totals_inconsistent', $field);
        }

        // POS-11: the server's tax for the line, at the rate in force when sold.
        $code = $this->sellability->taxCode($item, $company) ?? throw new Rejection(Sellability::TAX_CODE_MISSING, "{$field}.item_id", replace: ['item' => $item->name]);

        try {
            $expected = $this->taxes->forLine(Money::ofMinor((string) $amount, $currency), [$code], $inclusive, $at)->totalTax();
        } catch (TaxRateMissing) {
            throw new Rejection(Sellability::RATE_NEEDED, "{$field}.item_id", replace: ['item' => $item->name]);
        } catch (ApiException) {
            throw new Rejection('tax_code_archived', "{$field}.item_id", replace: ['item' => $item->name]);
        }

        $soldCode = $line['tax_code_id'] ?? null;

        if ($soldCode !== null && ! TaxCode::query()->whereKey($soldCode)->where('company_id', $company->id)->exists()) {
            throw new Rejection('tax_code_unknown', "{$field}.tax_code_id");
        }

        if ($soldCode !== $code->id || ! $tax->isEqualTo($expected->minor())) {
            // The device's own code and rate are kept here when the line stores the server's.
            $flags->add('tax_differs', $index + 1, [
                'expected_tax_minor' => $expected->minor(), 'tax_code' => $code->code,
                'sold_tax_code_id' => $soldCode, 'sold_tax_rate' => $line['tax_rate'] ?? null,
            ]);
        }

        // POS-10: a line the device sent without its tax code or rate stores the server's (the
        // code it resolved, its rate in force when sold), so the fiscal document can be sent.
        $lineCode = $soldCode ?? $code->id;
        $lineRate = $line['tax_rate'] ?? $this->rateOn($lineCode === $code->id ? $code : TaxCode::query()->findOrFail($lineCode), $at);

        // POS-07, RBAC-06, AUTH-08: a discount within the limit (`override`), a price other
        // than the list price (`price_override`). Users they name must exist even when unused.
        $this->authority->checkNamed($line['override'] ?? null, "{$field}.override");
        $this->authority->checkNamed($line['price_override'] ?? null, "{$field}.price_override");

        $percent = null;
        $discountApproval = null;

        if ($discount->isPositive()) {
            $percent = $discount->multipliedBy(100)->dividedBy($gross, 4, RoundingMode::HalfUp);
            $discountApproval = $this->restricted($place, $this->giver($line, $cashier), $line['override'] ?? null, $line['actor_proof'] ?? null, 'pos.discount.give',
                fn (User $user) => $this->authority->within($user, 'max_discount_percent', $place->scope(), $percent),
                $line['id'], "{$field}.override", 'discount_unauthorised', $index, $flags);
        }

        $priceApproval = null;
        $listPrice = $line['list_price_minor'] ?? null;

        if ($listPrice !== null && (string) $listPrice !== (string) $line['unit_price_minor']) {
            $priceApproval = $this->restricted($place, $this->giver($line, $cashier), $line['price_override'] ?? null, $line['actor_proof'] ?? null, 'pos.price.override', null,
                $line['id'], "{$field}.price_override", 'price_override_unauthorised', $index, $flags);
        }

        // M4: the server's price for the line (core item prices), compared, never imposed.
        if ($list !== null) {
            if ($listPrice === null) {
                $flags->add('list_price_missing', $index + 1);
            }

            $resolved = $this->prices->priceFor($item, $line['uom_id'], $list, $at, (string) $line['qty']);

            if ($resolved === null) {
                $flags->add('price_unknown', $index + 1);
            } elseif ($resolved->money->minor() !== (string) ($listPrice ?? $line['unit_price_minor'])) {
                $flags->add('price_differs', $index + 1, ['expected_unit_price_minor' => $resolved->money->minor()]);
            }
        }

        return [
            'row' => [
                'id' => $line['id'],
                'line_no' => $index + 1,
                'item_id' => $item->id,
                'item_name' => mb_substr((string) ($line['item_name'] ?? $item->name), 0, 255),
                'uom_id' => $line['uom_id'],
                'qty' => (string) $line['qty'],
                'unit_price_minor' => (string) $line['unit_price_minor'],
                'list_price_minor' => $listPrice === null ? null : (string) $listPrice,
                'price_list_id' => $list?->id,
                'tax_inclusive' => $inclusive,
                'discount_minor' => (string) $discount,
                'tax_code_id' => $lineCode,
                'tax_rate' => $lineRate,
                'net_minor' => (string) $total->minus($tax),
                'tax_minor' => (string) $tax,
                'total_minor' => (string) $total,
                'price_override_by' => $priceApproval?->approverId(),
                'discount_override_by' => $discountApproval?->approverId(),
            ],
            'gross' => $gross,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'item' => $item,
            'override' => $discountApproval,
            'price_override' => $priceApproval,
            'percent' => $percent,
        ];
    }

    /** A tax code's rate in force at $at (null when exempt or not set: rates are never invented). */
    private function rateOn(TaxCode $code, CarbonImmutable $at): ?string
    {
        $rate = $code->isExempt() ? null : $code->rateOn($at);

        return $rate === null || $rate->isNeeded() ? null : (string) $rate->rate;
    }

    /**
     * AUTH-07, POS-07: who gave a line's discount or price by their own
     * right. Tills switch users mid-sale, so a line's own proof may name
     * the person who changed it rather than the cashier completing the
     * sale; that person's right is checked. A user the tenant doesn't
     * know falls back to the cashier (the proof then doesn't verify).
     */
    private function giver(array $line, User $cashier): User
    {
        $named = $line['actor_proof']['user_id'] ?? null;

        if ($named === null || $named === $cashier->id) {
            return $cashier;
        }

        return User::query()->find($named) ?? $cashier;
    }

    /** A restricted line action: the approval, or null with a flag when nobody allowed it (device wins). */
    private function restricted(DevicePlace $place, User $cashier, ?array $override, ?array $actorProof, string $permission, ?\Closure $limit, string $subjectId, string $field, string $flag, int $index, Flags $flags): ?Approval
    {
        try {
            $approval = $this->authority->approve($cashier, $override, $actorProof, $permission, $place->scope(), $limit, $place->device, $subjectId, $field);
        } catch (Rejection $rejection) {
            // An unknown manager is a bad reference, not a missing permission.
            if ($rejection->errorCode === 'user_unknown') {
                throw $rejection;
            }

            $flags->add($flag, $index + 1, ['reason' => $rejection->errorCode]);

            return null;
        }

        // H3: money in is never held; what can't be proven is flagged.
        if ($approval->held()) {
            $flags->add($approval->flag(), $index + 1);
        }

        foreach ($approval->reviewFlags() as $code) {
            $flags->add($code, $index + 1);
        }

        return $approval;
    }

    /**
     * The sale's totals are the sums of its lines, as the till says.
     *
     * @param  list<array{gross: BigDecimal, discount: BigDecimal, tax: BigDecimal, total: BigDecimal}>  $lines
     * @return array{subtotal_minor: string, discount_minor: string, tax_minor: string, total_minor: string}
     */
    private function totals(array $lines, array $sent): array
    {
        $sum = fn (string $key) => array_reduce($lines, fn (BigDecimal $carry, array $line) => $carry->plus($line[$key]), BigDecimal::zero());
        $totals = [
            'subtotal_minor' => (string) $sum('gross'),
            'discount_minor' => (string) $sum('discount'),
            'tax_minor' => (string) $sum('tax'),
            'total_minor' => (string) $sum('total'),
        ];

        foreach ($totals as $key => $value) {
            if ((string) $sent[$key] !== $value) {
                throw new Rejection('sale_totals_inconsistent', "totals.{$key}");
            }
        }

        return $totals;
    }

    /**
     * CUR-06, CUR-09: each tender with the rate the till used; its amount
     * in the sale currency must be that conversion (within one minor unit:
     * the till shares rounding between tenders by largest remainder).
     *
     * @return array{0: list<array{row: array<string, mixed>, fx_snapshot: ?FxSnapshot}>, 1: BigDecimal, 2: BigDecimal} rows, paid as credited, paid exactly
     */
    private function payments(DevicePlace $place, array $payments, string $currency, CarbonImmutable $at, Flags $flags): array
    {
        $rows = [];
        $paid = BigDecimal::zero();
        $paidExact = BigDecimal::zero();

        foreach ($payments as $index => $payment) {
            $field = "payments.{$index}";
            $method = PaymentMethod::query()->find($payment['payment_method_id']);

            if ($method === null || $method->company_id !== $place->company->id) {
                throw new Rejection('payment_method_unknown', "{$field}.payment_method_id");
            }

            if ($method->currency !== null && $method->currency !== $payment['currency']) {
                throw new Rejection('payment_currency_mismatch', "{$field}.currency");
            }

            if (! TenantCurrency::query()->where('code', $payment['currency'])->exists()) {
                throw new Rejection('currency_unknown', "{$field}.currency");
            }

            $amount = Money::ofMinor((string) $payment['amount_minor'], $payment['currency']);
            $inSale = BigDecimal::of((string) $payment['amount_in_sale_minor']);
            $snapshot = Amounts::snapshot($payment['rate'] ?? null, $payment['currency'], $currency, "{$field}.rate");
            $exact = $snapshot === null ? BigDecimal::of($amount->minor()) : Amounts::exact($amount, $snapshot);

            if ($exact->minus($inSale)->abs()->isGreaterThanOrEqualTo(1)) {
                throw new Rejection('payment_conversion_mismatch', "{$field}.amount_in_sale_minor");
            }

            if ($snapshot !== null && ! $this->matchesServerRate($place, $snapshot, $at)) {
                $flags->add('rate_differs', null, ['payment' => $index + 1, 'pair' => "{$snapshot->base}/{$snapshot->quote}"]);
            }

            $paid = $paid->plus($inSale);
            $paidExact = $paidExact->plus($exact);
            $rows[] = [
                'row' => [
                    'id' => $payment['id'],
                    'payment_method_id' => $method->id,
                    'method_type' => $method->type,
                    'currency' => $payment['currency'],
                    'amount_minor' => $amount->minor(),
                    'amount_in_sale_minor' => (string) $inSale,
                    'fx' => $snapshot,
                    'provider_reference' => $payment['provider_reference'] ?? null,
                    'status' => $payment['status'] ?? SalePayment::CONFIRMED,
                ],
                'fx_snapshot' => $snapshot,
            ];
        }

        return [$rows, $paid, $paidExact];
    }

    /**
     * CUR-06: change in the chosen currency never exceeds the exact
     * overpayment (the tenders converted at their rates, unrounded, minus
     * the total); what the shop keeps from rounding the change down is
     * stored, half up to the sale currency's minor unit (as
     * TenderCalculator computes it).
     *
     * @return array{0: Money, 1: BigDecimal, 2: ?FxSnapshot}
     */
    private function change(?array $change, string $currency, BigDecimal $overpay): array
    {
        $changeCurrency = $change['currency'] ?? $currency;

        if (! TenantCurrency::query()->where('code', $changeCurrency)->exists()) {
            throw new Rejection('currency_unknown', 'change.currency');
        }

        $money = Money::ofMinor((string) ($change['amount_minor'] ?? 0), $changeCurrency);
        $snapshot = $money->isZero() && $changeCurrency !== $currency && ($change['rate'] ?? null) === null
            ? null
            : Amounts::snapshot($change['rate'] ?? null, $changeCurrency, $currency, 'change.rate');
        $inSale = $snapshot === null ? BigDecimal::of($money->minor()) : Amounts::exact($money, $snapshot);

        // A millionth of a minor unit absorbs the 20-decimal division.
        if ($inSale->isGreaterThan($overpay->plus('0.000001'))) {
            throw new Rejection('change_too_large', 'change.amount_minor');
        }

        $rounding = $overpay->minus($inSale)->toScale(0, RoundingMode::HalfUp);

        return [$money, $rounding->isNegative() ? BigDecimal::zero() : $rounding, $snapshot];
    }

    /**
     * CUR-02, CUR-04: the locked base currency, the snapshot used and the
     * base amounts (each line converted and rounded, then summed).
     *
     * @param  list<?FxSnapshot>  $tillRates
     * @return array{0: string, 1: FxSnapshot, 2: string, 3: string}
     */
    private function base(DevicePlace $place, array $lines, string $currency, CarbonImmutable $at, array $tillRates, Flags $flags): array
    {
        $company = $place->company;
        $base = $company->base_currency_locked_at !== null ? $company->base_currency : $this->baseLock->lock($company);

        if ($currency === $base) {
            $snapshot = FxSnapshot::identity($base);
        } else {
            try {
                $snapshot = FxSnapshot::fromRate($this->rates->stored($company, $currency, $base, $at));
            } catch (RateUnavailable) {
                $snapshot = collect($tillRates)->first(fn (?FxSnapshot $s) => $s !== null && in_array($currency, [$s->base, $s->quote], true) && in_array($base, [$s->base, $s->quote], true))
                    ?? throw new Rejection('rate_unavailable', 'currency', retryable: true);
                $flags->add('base_rate_from_till');
            }
        }

        $total = BigDecimal::zero();
        $tax = BigDecimal::zero();

        foreach ($lines as $line) {
            $total = $total->plus($snapshot->convert(Money::ofMinor((string) $line['total'], $currency))->minor());
            $tax = $tax->plus($snapshot->convert(Money::ofMinor((string) $line['tax'], $currency))->minor());
        }

        return [$base, $snapshot, (string) $total, (string) $tax];
    }

    /** CUR-09: the till's rate is the one the server holds for that time (either stored direction). */
    private function matchesServerRate(DevicePlace $place, FxSnapshot $snapshot, CarbonImmutable $at): bool
    {
        try {
            $stored = $this->rates->stored($place->company, $snapshot->base, $snapshot->quote, $at);
        } catch (RateUnavailable) {
            return false;
        }

        if ($stored->base === $snapshot->base) {
            return Rate::normalise($stored->mid) === $snapshot->rate;
        }

        return Rate::normalise($stored->invert()->mid) === $snapshot->rate;
    }

    /** AUD-01, AUD-02: the sale, and each discount and price override with who allowed it. */
    private function audit(Sale $sale, array $lines, User $cashier, CarbonImmutable $at, Money $total): void
    {
        $extra = ['user_id' => $cashier->id, 'device_time' => $at];

        $this->auditor->record('pos.sale.create', $sale, null, [
            'receipt_number' => $sale->receipt_number,
            'total' => $total,
            'flags' => $sale->flags,
        ], $extra);

        foreach ($lines as $line) {
            if ($line['discount']->isPositive()) {
                $this->auditor->record('pos.sale.discount', $sale, null, [
                    'line' => $line['row']['line_no'],
                    'discount_minor' => $line['row']['discount_minor'],
                    'percent' => (string) $line['percent'],
                    'approved_by' => $line['override']?->approverId(),
                    'allowed' => $line['override'] !== null,
                ], [...$extra, 'on_behalf_of_user_id' => $line['override']?->approverId()]);
            }

            if ($line['row']['list_price_minor'] !== null && $line['row']['list_price_minor'] !== $line['row']['unit_price_minor']) {
                $this->auditor->record('pos.sale.price_override', $sale, ['unit_price_minor' => $line['row']['list_price_minor']], [
                    'line' => $line['row']['line_no'],
                    'unit_price_minor' => $line['row']['unit_price_minor'],
                    'approved_by' => $line['price_override']?->approverId(),
                    'allowed' => $line['price_override'] !== null,
                ], [...$extra, 'on_behalf_of_user_id' => $line['price_override']?->approverId()]);
            }
        }
    }
}
