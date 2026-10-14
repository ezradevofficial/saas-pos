<?php

namespace Modules\Accounting\Integrations;

use App\Core\MasterData\Items\Item;
use App\Core\Currency\Money;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\SourceDocument;

/**
 * ACC-25: POS sales, refunds and voids as posting sources, built from the
 * snapshots POS sends with its events (never from POS tables). Every
 * amount is in the sale currency, at the sale's own rate (CUR-04).
 *
 * Fields of `pos.sale` (one journal per sale):
 *
 * - `tender.{payment method}`: what each method paid, in the sale currency;
 * - `change`: the change given, as its share of the sale currency;
 * - `rounding`: what the shop kept from rounding the change;
 * - `revenue.{item category}` (`revenue.none` without one): the lines'
 *   net, before promotion discounts (net + the promotion's net share);
 * - `promotion`: the promotion discounts (sources other than manual), net
 *   of tax: for a tax-inclusive line its share of the line's net
 *   (`round_half_up(discount × net / total)`), else as given;
 * - `tax.{tax code}`: the lines' tax per code.
 *
 * So Σ tender − change = total + rounding = Σ revenue − promotion + Σ tax +
 * rounding, exactly (in the sale currency). A tender paid in another
 * currency posts in that currency and amount (review #1); what the rates
 * then make the base amounts differ by goes to exchange gains or losses
 * (PostingService). A refund carries the same fields for what it gives
 * back (its own `rounding`: what its payments differ from its total by)
 * and a void the voided sale's, both posted with the sale rule's sides
 * swapped unless they have a rule of their own (`accounting.rule_fallbacks`).
 */
class PosSources
{
    /** @param array<string, mixed> $sale SaleSnapshot::sale() */
    public function sale(array $sale): SourceDocument
    {
        return $this->document('pos.sale', $sale['id'], $sale, $sale['sold_at'], $sale['lines'] ?? [], $sale['payments'] ?? [],
            (string) ($sale['change']['in_sale_minor'] ?? '0'), (string) ($sale['totals']['rounding_minor'] ?? '0'), (string) $sale['receipt_number']);
    }

    /** @param array<string, mixed> $sale SaleSnapshot::sale() of the voided sale */
    public function void(array $sale, string $voidId, ?string $voidedAt): SourceDocument
    {
        return $this->document('pos.void', $voidId, $sale, $voidedAt ?? ($sale['voided_at'] ?? CarbonImmutable::now()->toIso8601String()), $sale['lines'] ?? [], $sale['payments'] ?? [],
            (string) ($sale['change']['in_sale_minor'] ?? '0'), (string) ($sale['totals']['rounding_minor'] ?? '0'), (string) $sale['receipt_number']);
    }

    /** @param array<string, mixed> $refund SaleSnapshot::refund() */
    public function refund(array $refund): SourceDocument
    {
        return $this->document('pos.refund', $refund['id'], [...$refund, 'id' => $refund['sale_id']], $refund['refunded_at'], $refund['lines'] ?? [], $refund['payments'] ?? [],
            '0', (string) ($refund['rounding_minor'] ?? '0'), (string) $refund['receipt_number']);
    }

    /**
     * @param  array<string, mixed>  $record  the sale (or the refund, with the sale's id as `id`)
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $payments
     */
    private function document(string $type, string $id, array $record, string $at, array $lines, array $payments, string $change, string $rounding, string $reference): SourceDocument
    {
        $amounts = [];
        $add = function (string $field, string|BigInteger $minor) use (&$amounts): void {
            $amounts[$field] = (string) BigInteger::of($amounts[$field] ?? '0')->plus($minor);
        };

        foreach ($payments as $payment) {
            $add('tender.'.$payment['payment_method_id'], (string) $payment['amount_in_sale_minor']);
        }

        $foreign = $this->foreignTenders($record, $payments);

        $add('change', $change);
        $add('rounding', $rounding);
        $categories = Item::query()->whereKey(array_values(array_filter(array_unique(array_column($lines, 'item_id')))))->pluck('category_id', 'id');

        foreach ($lines as $line) {
            $net = BigInteger::of((string) ($line['net_minor'] ?? '0'));
            $promotion = $this->promotionNet($line);
            $category = $categories->get($line['item_id'] ?? '') ?? 'none';
            $add("revenue.{$category}", $net->plus($promotion));
            $add('promotion', $promotion);
            $add('tax.'.($line['tax_code_id'] ?? 'none'), (string) ($line['tax_minor'] ?? '0'));
        }

        $fx = $record['base']['fx'] ?? null;

        return new SourceDocument(
            companyId: $record['company_id'],
            sourceType: $type,
            sourceId: $id,
            date: CarbonImmutable::parse($at),
            currency: $record['currency'],
            amounts: array_filter($amounts, fn (string $minor) => $minor !== '0'),
            fx: ($record['base']['currency'] ?? $record['currency']) === $record['currency'] || ! is_array($fx) ? null : Integrations::fx($fx),
            dimensions: array_filter(['branch_id' => $record['branch_id'] ?? null, 'party_id' => $record['customer_id'] ?? null]),
            reference: $reference,
            link: ['kind' => 'pos.sale', 'id' => $record['id']],
            foreign: $foreign,
        );
    }

    /**
     * Review #1: tenders paid in another currency than the sale's post in
     * that currency and amount. Their base amount: the amount itself when
     * paid in the base currency; else converted at the payment's own rate
     * when the sale is in the base currency; else the payment's value in the
     * sale currency at the sale's rate. A method paid in several currencies
     * on one sale stays in the sale currency.
     *
     * @param  list<array<string, mixed>>  $payments
     * @return array<string, array{currency: string, amount_minor: string, base_minor: string, fx: ?array}>
     */
    private function foreignTenders(array $record, array $payments): array
    {
        $sale = (string) $record['currency'];
        $base = (string) ($record['base']['currency'] ?? $sale);
        $byField = [];

        foreach ($payments as $payment) {
            $byField['tender.'.$payment['payment_method_id']][] = $payment;
        }

        $foreign = [];

        foreach ($byField as $field => $rows) {
            $currencies = array_unique(array_column($rows, 'currency'));
            $currency = (string) $currencies[0];

            if (count($currencies) !== 1 || $currency === $sale) {
                continue;
            }

            $amount = BigInteger::zero();
            $baseAmount = BigInteger::zero();
            $fx = null;

            foreach ($rows as $row) {
                $paid = Money::ofMinor((string) $row['amount_minor'], $currency);
                $rowFx = is_array($row['fx'] ?? null) ? Integrations::fx($row['fx']) : null;
                $saleFx = is_array($record['base']['fx'] ?? null) ? Integrations::fx($record['base']['fx']) : null;
                $value = match (true) {
                    $currency === $base => $paid,
                    $sale === $base && $rowFx !== null => $rowFx->convert($paid),
                    $saleFx !== null && ! $saleFx->isIdentity() => $saleFx->convert(Money::ofMinor((string) $row['amount_in_sale_minor'], $sale)),
                    default => null,
                };

                if ($value === null) {
                    continue 2;
                }

                $amount = $amount->plus($paid->minor());
                $baseAmount = $baseAmount->plus($value->minor());
                $fx = $rowFx !== null && in_array($base, [$rowFx->base(), $rowFx->quote()], true) && in_array($currency, [$rowFx->base(), $rowFx->quote()], true) ? $row['fx'] : $fx;
            }

            $foreign[$field] = ['currency' => $currency, 'amount_minor' => (string) $amount, 'base_minor' => (string) $baseAmount, 'fx' => $fx];
        }

        return $foreign;
    }

    /** A line's promotion discounts (every source but manual), net of tax. */
    private function promotionNet(array $line): BigInteger
    {
        $promotion = BigInteger::zero();

        foreach ((array) ($line['discount']['sources'] ?? []) as $minor) {
            $promotion = $promotion->plus((string) $minor);
        }

        $total = BigInteger::of((string) ($line['total_minor'] ?? '0'));

        if ($promotion->isZero() || ! ($line['tax_inclusive'] ?? false) || $total->isZero()) {
            return $promotion;
        }

        return $promotion->multipliedBy((string) $line['net_minor'])->dividedBy($total, RoundingMode::HalfUp);
    }
}
