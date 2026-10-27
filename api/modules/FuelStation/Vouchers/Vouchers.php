<?php

namespace Modules\FuelStation\Vouchers;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\FuelStation\Models\Voucher;
use Modules\FuelStation\Models\VoucherRedemption;
use Modules\FuelStation\Support\FuelError;

/**
 * FUEL-21: prepaid fuel vouchers. A voucher has a code (unique per company,
 * ignoring case), a value in its currency and a balance. Redeeming takes the
 * value of a sale's `fuel_voucher` payment off the balance under a row lock,
 * once per sale and payment; the sale was completed at the till (the money
 * has changed hands), so a payment above the balance is recorded in full,
 * the balance goes to 0 and the redemption is flagged `voucher_overdrawn`.
 * A voided or refunded sale gives the value back.
 */
class Vouchers
{
    /** No 0, O, 1, I, L: codes are read out and typed. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly Auditor $auditor) {}

    public static function normalise(string $code): string
    {
        return mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public function find(string $companyId, string $code): ?Voucher
    {
        return Voucher::query()->where('company_id', $companyId)->whereRaw('upper(code) = ?', [self::normalise($code)])->first();
    }

    /**
     * @param  array{company_id: string, value_minor: string|int, currency: string, code?: ?string, party_id?: ?string, fuel_type?: ?string, expires_on?: ?string}  $data
     */
    public function issue(array $data, User $by): Voucher
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = isset($data['code']) && $data['code'] !== null && $data['code'] !== '' ? self::normalise($data['code']) : $this->generate();

            try {
                return DB::connection(TenantContext::CONNECTION)->transaction(fn () => Voucher::create([
                    'company_id' => $data['company_id'], 'party_id' => $data['party_id'] ?? null, 'code' => $code, 'currency' => $data['currency'],
                    'value_minor' => (string) $data['value_minor'], 'balance_minor' => (string) $data['value_minor'],
                    'fuel_type' => $data['fuel_type'] ?? null, 'expires_on' => $data['expires_on'] ?? null, 'issued_by' => $by->id,
                ])->refresh());
            } catch (UniqueConstraintViolationException) {
                if (isset($data['code']) && $data['code'] !== '') {
                    throw FuelError::make(422, 'voucher_code_used', field: 'code');
                }
            }
        }

        throw FuelError::make(500, 'voucher_code_failed');
    }

    public function void(Voucher $voucher, string $reason, User $by): Voucher
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($voucher, $reason, $by) {
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($voucher->status === Voucher::VOID) {
                throw FuelError::make(409, 'voucher_void');
            }

            $voucher->forceFill(['status' => Voucher::VOID, 'void_reason' => $reason, 'voided_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('fuel.voucher.void', $voucher, ['status' => Voucher::ACTIVE], ['status' => Voucher::VOID, 'reason' => $reason, 'balance_minor' => (string) $voucher->balance_minor], ['user_id' => $by->id]);

            return $voucher;
        });
    }

    /**
     * FUEL-21: takes what each `fuel_voucher` payment of the sale really paid off its voucher. An unknown voucher takes nothing and leaves
     * no row; a void, expired or other-currency voucher is recorded with 0 applied and flagged; a payment above the balance applies the
     * balance only (`voucher_overdrawn`). The shift's collections count `applied_minor`, never the payment's face value.
     *
     * @param  array<string, mixed>  $sale  SaleSnapshot::sale()
     */
    public function redeem(array $sale): void
    {
        foreach ((array) ($sale['payments'] ?? []) as $payment) {
            if (($payment['method_type'] ?? null) !== 'fuel_voucher') {
                continue;
            }

            $code = (string) ($payment['tender']['code'] ?? '');
            $voucher = $code === '' ? null : $this->find($sale['company_id'], $code);

            if ($voucher === null) {
                continue;
            }

            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($voucher, $sale, $payment) {
                $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

                if (VoucherRedemption::query()->where('sale_id', $sale['id'])->where('payment_id', $payment['id'])->exists()) {
                    return;
                }

                $amount = (int) $payment['amount_in_sale_minor'];
                $soldAt = CarbonImmutable::parse($sale['sold_at'])->utc();
                $zone = Company::query()->whereKey($sale['company_id'])->value('timezone') ?: 'UTC';
                $flags = [];

                if ($voucher->status === Voucher::VOID) {
                    $flags[] = ['code' => 'voucher_void'];
                }

                if ($voucher->expires_on !== null && $voucher->expires_on->toDateString() < $soldAt->setTimezone($zone)->toDateString()) {
                    $flags[] = ['code' => 'voucher_expired'];
                }

                if ($voucher->currency !== $sale['currency']) {
                    $flags[] = ['code' => 'voucher_currency'];
                }

                $applied = $flags === [] ? min($amount, (int) $voucher->balance_minor) : 0;

                if ($flags === [] && $amount > $applied) {
                    $flags[] = ['code' => 'voucher_overdrawn', 'balance_minor' => (string) $voucher->balance_minor];
                }

                VoucherRedemption::create([
                    'voucher_id' => $voucher->id, 'location_id' => $sale['location_id'], 'sale_id' => $sale['id'], 'payment_id' => $payment['id'],
                    'amount_minor' => $amount, 'applied_minor' => $applied, 'currency' => $sale['currency'], 'redeemed_at' => $soldAt, 'flags' => $flags,
                ]);
                $voucher->forceFill(['balance_minor' => (int) $voucher->balance_minor - $applied])->save();
                $this->auditor->record('fuel.voucher.redeem', $voucher, null, ['sale_id' => $sale['id'], 'amount_minor' => (string) $amount, 'applied_minor' => (string) $applied, 'balance_minor' => (string) $voucher->balance_minor, 'flags' => array_column($flags, 'code')]);
            });
        }
    }

    /** A voided sale gives back what its vouchers really paid and was not already refunded (never above the voucher's value). */
    public function reverse(string $saleId, ?string $at): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($saleId, $at) {
            foreach (VoucherRedemption::query()->where('sale_id', $saleId)->whereNull('reversed_at')->lockForUpdate()->get() as $redemption) {
                $voucher = Voucher::query()->whereKey($redemption->voucher_id)->lockForUpdate()->firstOrFail();
                $back = (int) $redemption->applied_minor - (int) $redemption->refunded_minor;
                $voucher->forceFill(['balance_minor' => min((int) $voucher->value_minor, (int) $voucher->balance_minor + $back)])->save();
                $redemption->forceFill(['reversed_at' => CarbonImmutable::parse($at ?? now())->utc(), 'refunded_minor' => $redemption->applied_minor])->save();
                $this->auditor->record('fuel.voucher.reverse', $voucher, null, ['sale_id' => $saleId, 'amount_minor' => (string) $back, 'balance_minor' => (string) $voucher->balance_minor]);
            }
        });
    }

    /**
     * FUEL-21: a refund paid back to a voucher gives its value back, once per refund, and never more than the voucher really paid for that sale.
     *
     * @param  array<string, mixed>  $refund  SaleSnapshot::refund()
     */
    public function refund(array $refund): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($refund) {
            foreach ((array) ($refund['payments'] ?? []) as $payment) {
                if (($payment['method_type'] ?? null) !== 'fuel_voucher') {
                    continue;
                }

                $voucher = $this->find($refund['company_id'], (string) ($payment['tender']['code'] ?? ''));
                $redemption = $voucher === null ? null : VoucherRedemption::query()->where('sale_id', $refund['sale_id'])->where('voucher_id', $voucher->id)->whereNull('reversed_at')->lockForUpdate()->first();

                if ($redemption === null || in_array($refund['id'], $redemption->refund_ids ?? [], true)) {
                    continue;
                }

                $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
                $give = max(0, min((int) $payment['amount_in_sale_minor'], (int) $redemption->applied_minor - (int) $redemption->refunded_minor));
                $voucher->forceFill(['balance_minor' => min((int) $voucher->value_minor, (int) $voucher->balance_minor + $give)])->save();
                $redemption->forceFill(['refunded_minor' => (int) $redemption->refunded_minor + $give, 'refund_ids' => [...($redemption->refund_ids ?? []), $refund['id']]])->save();
                $this->auditor->record('fuel.voucher.refund', $voucher, null, ['sale_id' => $refund['sale_id'], 'refund_id' => $refund['id'], 'amount_minor' => (string) $give, 'balance_minor' => (string) $voucher->balance_minor]);
            }
        });
    }

    private function generate(): string
    {
        $code = '';

        for ($i = 0; $i < 10; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
