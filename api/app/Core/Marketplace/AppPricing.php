<?php

namespace App\Core\Marketplace;

/**
 * MKT-01, MKT-03: what an app costs, as its developer set it on a version.
 *
 * `{model: free}`, or `{model: paid, monthly: {KES: n, USD: n, CDF: n},
 * yearly: {...}}` in minor units (CDF has no decimals). A paid app prices
 * at least one currency monthly; the yearly price, when given, is for the
 * same currencies, and defaults to twelve months. A tenant pays in its
 * subscription's currency, on its subscription's cycle (AppBilling).
 */
final class AppPricing
{
    public const FREE = 'free';

    public const PAID = 'paid';

    public const CURRENCIES = ['KES', 'USD', 'CDF'];

    public const MAX_MINOR = 1_000_000_000;

    /**
     * @param  array<string, int>  $monthly
     * @param  array<string, int>  $yearly
     */
    private function __construct(
        public readonly string $model,
        public readonly array $monthly,
        public readonly array $yearly,
    ) {}

    public static function from(array $pricing): self
    {
        $model = ($pricing['model'] ?? self::FREE) === self::PAID ? self::PAID : self::FREE;
        $clean = fn ($prices) => array_map('intval', array_filter(is_array($prices) ? $prices : [], fn ($v, $k) => in_array($k, self::CURRENCIES, true) && is_numeric($v), ARRAY_FILTER_USE_BOTH));

        return $model === self::FREE ? new self(self::FREE, [], []) : new self(self::PAID, $clean($pricing['monthly'] ?? []), $clean($pricing['yearly'] ?? []));
    }

    public function isFree(): bool
    {
        return $this->model === self::FREE || $this->monthly === [];
    }

    /** The price of one period of $cycle (`monthly` or `yearly`) in $currency, or null when the app is not sold in it. */
    public function priceFor(string $currency, string $cycle): ?int
    {
        if ($this->isFree()) {
            return 0;
        }

        if (! isset($this->monthly[$currency])) {
            return null;
        }

        return $cycle === 'yearly' ? ($this->yearly[$currency] ?? $this->monthly[$currency] * 12) : $this->monthly[$currency];
    }

    /** @return array{currency: string, monthly_minor: int, yearly_minor: int}|null what a tenant paying in $currency is charged; null for a free app */
    public function snapshot(string $currency): ?array
    {
        if ($this->isFree()) {
            return null;
        }

        $monthly = $this->priceFor($currency, 'monthly');

        return $monthly === null ? null : ['currency' => $currency, 'monthly_minor' => $monthly, 'yearly_minor' => (int) $this->priceFor($currency, 'yearly')];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->model === self::FREE ? ['model' => self::FREE] : array_filter([
            'model' => self::PAID,
            'monthly' => $this->monthly,
            'yearly' => $this->yearly === [] ? null : $this->yearly,
        ], fn ($v) => $v !== null);
    }

    /**
     * Problems with a pricing as submitted (field => message key), for the
     * Form Request and for submission.
     *
     * @return array<string, string>
     */
    public static function problems(mixed $pricing): array
    {
        if (! is_array($pricing) || ! in_array($pricing['model'] ?? null, [self::FREE, self::PAID], true)) {
            return ['pricing.model' => 'marketplace.validation.pricing_model'];
        }

        if ($pricing['model'] === self::FREE) {
            return [];
        }

        $problems = [];
        $monthly = $pricing['monthly'] ?? null;

        if (! is_array($monthly) || $monthly === []) {
            return ['pricing.monthly' => 'marketplace.validation.pricing_monthly'];
        }

        foreach (['monthly' => $monthly, 'yearly' => $pricing['yearly'] ?? []] as $cycle => $prices) {
            if (! is_array($prices)) {
                $problems["pricing.{$cycle}"] = 'marketplace.validation.pricing_amount';

                continue;
            }

            foreach ($prices as $currency => $amount) {
                if (! in_array($currency, self::CURRENCIES, true)) {
                    $problems["pricing.{$cycle}.{$currency}"] = 'marketplace.validation.pricing_currency';
                } elseif (! is_int($amount) || $amount < 1 || $amount > self::MAX_MINOR) {
                    $problems["pricing.{$cycle}.{$currency}"] = 'marketplace.validation.pricing_amount';
                } elseif ($cycle === 'yearly' && ! array_key_exists($currency, $monthly)) {
                    $problems["pricing.{$cycle}.{$currency}"] = 'marketplace.validation.pricing_yearly_currency';
                }
            }
        }

        return $problems;
    }
}
