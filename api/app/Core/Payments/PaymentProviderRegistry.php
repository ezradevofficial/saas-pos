<?php

namespace App\Core\Payments;

use App\Core\Api\Sandbox\Sandbox;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\PaymentMethods\PaymentProviders;
use App\Core\Payments\Contracts\PaymentProvider;
use App\Core\Payments\Daraja\MpesaDarajaProvider;
use App\Core\Payments\Drivers\AfrimoneyProvider;
use App\Core\Payments\Drivers\AirtelMoneyProvider;
use App\Core\Payments\Drivers\CashProvider;
use App\Core\Payments\Drivers\FakeProvider;
use App\Core\Payments\Drivers\HostedCardProvider;
use App\Core\Payments\Drivers\ManualProvider;
use App\Core\Payments\Drivers\OrangeMoneyProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

/**
 * The adapter for a payment method, keyed by its provider (concept note
 * 7.1): the provider's `driver` in config/payment_providers.php, unless
 * `payments.drivers` overrides it (local work against the fake driver).
 * A method without a provider (cash, credit, voucher, ...) uses `cash`.
 * Modules may register more adapters (an aggregator) in their provider's
 * boot().
 *
 * API-05: in a developer sandbox every method with a provider uses the
 * fake driver, in any environment: no money moves there (ADR 020).
 */
class PaymentProviderRegistry
{
    /** @var array<string, class-string<PaymentProvider>> */
    private array $drivers = [
        'cash' => CashProvider::class,
        'manual' => ManualProvider::class,
        'fake' => FakeProvider::class,
        'mpesa_daraja' => MpesaDarajaProvider::class,
        // SUB-04: the platform's own billing (unavailable until configured).
        'airtel_money' => AirtelMoneyProvider::class,
        'orange_money' => OrangeMoneyProvider::class,
        'afrimoney' => AfrimoneyProvider::class,
        'card_hosted' => HostedCardProvider::class,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly PaymentProviders $providers,
        private readonly Sandbox $sandbox,
    ) {}

    /** @param class-string<PaymentProvider> $class */
    public function register(string $name, string $class): void
    {
        $this->drivers[$name] = $class;
    }

    public function driverName(PaymentMethod $method): string
    {
        if ($method->provider !== null && $this->sandbox->active()) {
            return 'fake';
        }

        $override = $method->provider === null ? null : config("payments.drivers.{$method->provider}");

        return is_string($override) && $override !== '' ? $override : $this->providers->driverOf($method->provider);
    }

    public function for(PaymentMethod $method): PaymentProvider
    {
        return $this->driver($this->driverName($method));
    }

    public function driver(string $name): PaymentProvider
    {
        $class = $this->drivers[$name] ?? throw new InvalidArgumentException("Unknown payment driver [{$name}].");

        // NFR-06: the fake driver confirms money that never moved.
        if ($name === 'fake' && ! config('payments.allow_fake') && ! $this->sandbox->active()) {
            throw new RuntimeException('The fake payment driver is only available in local and testing.');
        }

        return $this->container->make($class);
    }
}
