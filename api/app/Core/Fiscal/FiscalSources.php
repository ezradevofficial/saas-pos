<?php

namespace App\Core\Fiscal;

use App\Core\Fiscal\Contracts\FiscalDocumentSource;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** The modules whose documents go to the tax authority, by source key (FiscalDocumentSource). */
class FiscalSources
{
    /** @var array<string, class-string<FiscalDocumentSource>|FiscalDocumentSource> */
    private array $sources = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<FiscalDocumentSource>|FiscalDocumentSource $source */
    public function register(string $key, string|FiscalDocumentSource $source): void
    {
        $this->sources[$key] = $source;
    }

    public function get(string $key): FiscalDocumentSource
    {
        $source = $this->sources[$key] ?? throw new InvalidArgumentException("Unknown fiscal document source [{$key}].");

        return $source instanceof FiscalDocumentSource ? $source : $this->container->make($source);
    }
}
