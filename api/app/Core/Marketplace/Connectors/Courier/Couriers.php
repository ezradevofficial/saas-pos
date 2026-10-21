<?php

namespace App\Core\Marketplace\Connectors\Courier;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * MKT-04: the courier driver, by `marketplace.connectors.courier.driver`.
 * `none` (the default) is not configured; `fake` works only in local and
 * testing. A real courier is added as a driver here once the owner has
 * chosen one and given its credentials (never invented).
 */
class Couriers
{
    private ?CourierDriver $driver = null;

    public function __construct(private readonly Application $app) {}

    public function driver(): CourierDriver
    {
        return $this->driver ??= match ($name = (string) config('marketplace.connectors.courier.driver', 'none')) {
            'none', '' => new UnconfiguredCourier,
            'fake' => $this->app->environment(['local', 'testing']) ? new FakeCourier : new UnconfiguredCourier,
            default => throw new InvalidArgumentException("Unknown courier driver [{$name}]."),
        };
    }

    /** Replace the driver (tests, or a provider package). */
    public function extend(CourierDriver $driver): void
    {
        $this->driver = $driver;
    }
}
