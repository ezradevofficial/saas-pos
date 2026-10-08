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

    /** @return list<string> every setting key the provider takes, required first */
    public function settingKeys(?string $provider): array
    {
        return $provider === null ? [] : [...($this->all()[$provider]['settings'] ?? []), ...($this->all()[$provider]['optional_settings'] ?? [])];
    }

    /** @return list<string> every secret key the provider takes, required first */
    public function secretKeys(?string $provider): array
    {
        return $provider === null ? [] : [...($this->all()[$provider]['secrets'] ?? []), ...($this->all()[$provider]['optional_secrets'] ?? [])];
    }

    /** @return array<string, list<string>> setting key => the only values it takes */
    public function settingValues(?string $provider): array
    {
        return $provider === null ? [] : ($this->all()[$provider]['setting_values'] ?? []);
    }

    /** The payment adapter for $provider (App\Core\Payments): `manual` when none is named. */
    public function driverOf(?string $provider): string
    {
        return $provider === null ? 'cash' : ($this->all()[$provider]['driver'] ?? 'manual');
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

        $provider = $method->provider === null ? [] : ($this->all()[$method->provider] ?? []);

        foreach ($provider['settings'] ?? [] as $key) {
            if (blank($settings[$key] ?? null)) {
                $missing[] = $key;
            }
        }

        foreach ($provider['secrets'] ?? [] as $key) {
            if (blank($secrets[$key] ?? null)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /** @return array<string, array{type: string, countries: list<string>, driver?: string, settings: list<string>, secrets: list<string>, optional_settings?: list<string>, optional_secrets?: list<string>, setting_values?: array<string, list<string>>}> */
    private function all(): array
    {
        return config('payment_providers', []);
    }
}
