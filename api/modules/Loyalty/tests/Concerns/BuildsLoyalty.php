<?php

namespace Modules\Loyalty\Tests\Concerns;

use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Testing\TestResponse;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\Programme;
use Modules\Loyalty\Pos\PosSales;
use Modules\POS\Tests\Concerns\BuildsPos;

/**
 * BuildsPos plus the Loyalty module: Acme's programme (one point per KES
 * 100.00, a point worth KES 1.00, switched on), points, voucher and credit
 * payment methods, and Amina, a VIP customer. Sales are made through the
 * till's real upload API, so POS raises its events as in production.
 */
trait BuildsLoyalty
{
    use BuildsPos;

    protected Programme $programme;

    protected Party $amina;

    protected function setUpLoyalty(array $programme = []): void
    {
        $this->setUpPos();

        $this->inTenant(function () use ($programme) {
            app(ModuleRegistry::class)->activate('loyalty');
            $this->programme = Programme::create([
                'company_id' => $this->acme->id, 'active' => true, 'currency' => 'KES', 'earn_points' => 1,
                'earn_spend_minor' => '10000', 'point_value_minor' => '100', 'min_redeem_points' => '0', ...$programme,
            ]);
            $this->methods['points'] = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'points', 'name' => 'Points', 'currency' => 'KES', 'active' => true, 'position' => 4]);
            $this->methods['voucher'] = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'voucher', 'name' => 'Gift card', 'active' => true, 'position' => 5]);
            $this->methods['credit'] = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'credit', 'name' => 'On account', 'active' => true, 'position' => 6]);
            $this->amina = Party::create(['kind' => 'person', 'name' => 'Amina', 'roles' => ['customer'], 'tags' => ['vip']]);
        });
    }

    /** A sale of `$qty` soaps (KES 562.50 each, tax included) for $customer, paid by $payments (default cash). */
    protected function loyaltySale(string $shift, int $seq, ?string $customer, array $payments = [], string $qty = '2', array $extra = []): array
    {
        $total = (string) (56250 * (int) $qty);
        $tax = (string) intdiv(56250 * (int) $qty, 9);
        $lines = [$this->line(['qty' => $qty, 'tax_minor' => $tax, 'total_minor' => $total])];

        return $this->saleBody($shift, $seq, [
            'customer_id' => $customer,
            'lines' => $lines,
            'payments' => $payments === [] ? [$this->pay('cash_kes', $total)] : $payments,
            ...$extra,
        ]);
    }

    protected function pay(string $method, string $amount, ?array $tender = null): array
    {
        return array_filter([
            'id' => $this->id(),
            'payment_method_id' => $this->methods[$method]->id,
            'currency' => 'KES',
            'amount_minor' => $amount,
            'amount_in_sale_minor' => $amount,
            'status' => 'confirmed',
            'tender' => $tender,
        ], fn ($value) => $value !== null);
    }

    /** An active card of $amount (KES minor), and its code. @return array{0: GiftCard, 1: string} */
    protected function giftCard(string $amount = '100000', bool $active = true): array
    {
        return $this->inTenant(function () use ($amount, $active) {
            [$issued] = app(GiftCards::class)->issue(['company_id' => $this->acme->id, 'kind' => GiftCard::GIFT_CARD, 'currency' => 'KES', 'amount_minor' => $amount, 'active' => $active], 1, $this->owner);

            return [$issued['card']->refresh(), $issued['code']];
        });
    }

    /** Amina's open credit account at Acme, with her core credit limit (KES minor; null: none). */
    protected function creditAccount(?string $limit = '500000'): Account
    {
        return $this->inTenant(function () use ($limit) {
            $this->amina->forceFill(['credit_limit_minor' => $limit, 'credit_limit_currency' => $limit === null ? null : 'KES'])->saveQuietly();

            return Account::create([
                'company_id' => $this->acme->id, 'party_id' => $this->amina->id, 'kind' => Account::CREDIT, 'currency' => 'KES',
                'balance' => 0, 'status' => Account::OPEN, 'opened_at' => now(), 'opened_by' => $this->owner->id,
            ]);
        });
    }

    protected function pointsOf(string $partyId): string
    {
        return (string) ($this->inTenant(fn () => Account::query()->where('party_id', $partyId)->where('kind', Account::POINTS)->value('balance')) ?? '0');
    }

    protected function voidSale(string $saleId): TestResponse
    {
        return $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $this->id(), 'sale_id' => $saleId, 'voided_by_id' => $this->owner->id,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong customer', 'override' => $this->override($this->owner->id),
        ]]], $this->tillHeaders());
    }

    protected function refundSale(array $sale, string $shift, string $qty, string $total, int $seq, array $payments = []): TestResponse
    {
        return $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $this->id(), 'sale_id' => $sale['id'], 'shift_id' => $shift, 'cashier_id' => $this->owner->id,
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(),
            'reason' => 'Damaged', 'total_minor' => $total,
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => $qty]],
            'payments' => $payments === [] ? [$this->pay('cash_kes', $total)] : $payments,
            'override' => $this->override($this->owner->id),
        ]]], $this->tillHeaders());
    }

    protected function promotionSource(): string
    {
        return PosSales::PROMOTION_SOURCE;
    }
}
