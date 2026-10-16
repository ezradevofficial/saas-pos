<?php

namespace App\Core\Fiscal;

use App\Core\Api\Sandbox\Sandbox;
use App\Core\Fiscal\Contracts\FiscalDriver;
use App\Core\Fiscal\Drivers\DgiEmcfDriver;
use App\Core\Fiscal\Drivers\FakeFiscalDriver;
use App\Core\Fiscal\Etims\EtimsOscuDriver;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The tax authority adapters by name, and which a company may choose:
 * its country's drivers (`fiscal.countries.{country}.drivers`) and, in
 * local and testing only, `fake` (NFR-06). API-05: a developer sandbox
 * always uses `fake`, in any environment; nothing reaches a tax authority
 * from it (ADR 020).
 */
class FiscalDrivers
{
    /** @var array<string, class-string<FiscalDriver>> */
    private array $drivers = [
        'fake' => FakeFiscalDriver::class,
        'kra_etims_oscu' => EtimsOscuDriver::class,
        'dgi_emcf' => DgiEmcfDriver::class,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly Sandbox $sandbox,
    ) {}

    /** @param class-string<FiscalDriver> $class */
    public function register(string $name, string $class): void
    {
        $this->drivers[$name] = $class;
    }

    public function get(string $name): FiscalDriver
    {
        if ($this->sandbox->active()) {
            $name = 'fake';
        }

        $class = $this->drivers[$name] ?? throw new InvalidArgumentException("Unknown fiscal driver [{$name}].");

        return $this->container->make($class);
    }

    /** @return list<string> the drivers a company in $country may choose */
    public function choices(string $country): array
    {
        if ($this->sandbox->active()) {
            return ['fake'];
        }

        $drivers = (array) config("fiscal.countries.{$country}.drivers", []);

        if (config('fiscal.allow_fake')) {
            $drivers[] = 'fake';
        }

        // The country's own drivers first: a new company's settings default to the first.
        return array_values(array_filter(array_unique($drivers), fn (string $name) => isset($this->drivers[$name])));
    }
}
