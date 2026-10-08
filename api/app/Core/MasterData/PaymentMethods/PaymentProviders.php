<?php

namespace App\Core\MasterData\PaymentMethods;

/**
 * MD-04: the payment providers (`config/payment_providers.php`): which
 * method type each serves, its plain settings and its secret keys. A
 * mobile money or card method needs every one of them before it can be
 * switched on.
 */
class PaymentProviders
{
    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function has(?string $provider): bool
    {
        return $provider !== null && isset($this->all()[$provider]);
    }

    public function typeOf(string $provider): ?string
    {
        return $this->all()[$provider]['type'] ?? null;
    }

    /** @return list<string> */
    public function settingKeys(?string $provider): array
    {
        return $provider === null ? [] : ($this->all()[$provider]['settings'] ?? []);
    }

    /** @return list<string> */
    public function secretKeys(?string $provider): array
    {
        return $provider === null ? [] : ($this->all()[$provider]['secrets'] ?? []);
    }

    /**
     * Providers seeded for a company in $country, in config order.
     *
     * @return list<string>
     */
    public function forCountry(string $country): array
    {
        return array_keys(array_filter($this->all(), fn (array $provider) => in_array($country, $provider['countries'] ?? [], true)));
    }

    /**
     * The settings and secrets $method still lacks (empty when configured).
     *
     * @return list<string>
     */
    public function missing(PaymentMethod $method): array
    {
        $settings = $method->settings ?? [];
        $secrets = $method->secrets ?? [];
        $missing = [];

        foreach ($this->settingKeys($method->provider) as $key) {
            if (blank($settings[$key] ?? null)) {
                $missing[] = $key;
            }
        }

        foreach ($this->secretKeys($method->provider) as $key) {
            if (blank($secrets[$key] ?? null)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /** @return array<string, array{type: string, countries: list<string>, settings: list<string>, secrets: list<string>}> */
    private function all(): array
    {
        return config('payment_providers', []);
    }
}
