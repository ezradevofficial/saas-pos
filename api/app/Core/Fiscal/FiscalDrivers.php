<?php

namespace App\Core\Fiscal;

use App\Core\Fiscal\Contracts\FiscalDriver;
use App\Core\Fiscal\Drivers\DgiEmcfDriver;
use App\Core\Fiscal\Drivers\FakeFiscalDriver;
use App\Core\Fiscal\Etims\EtimsOscuDriver;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The tax authority adapters by name, and which a company may choose:
 * its country's drivers (`fiscal.countries.{country}.drivers`) and, in
 * local and testing only, `fake` (NFR-06).
 */
class FiscalDrivers
{
    /** @var array<string, class-string<FiscalDriver>> */
    private array $drivers = [
        'fake' => FakeFiscalDriver::class,
        'kra_etims_oscu' => EtimsOscuDriver::class,
        'dgi_emcf' => DgiEmcfDriver::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<FiscalDriver> $class */
    public function register(string $name, string $class): void
    {
        $this->drivers[$name] = $class;
    }

    public function get(string $name): FiscalDriver
    {
        $class = $this->drivers[$name] ?? throw new InvalidArgumentException("Unknown fiscal driver [{$name}].");

        return $this->container->make($class);
    }

    /** @return list<string> the drivers a company in $country may choose */
    public function choices(string $country): array
    {
        $drivers = (array) config("fiscal.countries.{$country}.drivers", []);

        if (config('fiscal.allow_fake')) {
            $drivers[] = 'fake';
        }

        return array_values(array_intersect(array_keys($this->drivers), $drivers));
    }
}
