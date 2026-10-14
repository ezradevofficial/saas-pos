<?php

namespace App\Core\MasterData\Prices;

use App\Core\Rbac\ModuleRegistry;
use Closure;
use InvalidArgumentException;

/**
 * POS-07, LOY-11: modules that vouch for part of a line's discount (the
 * Loyalty module's promotions, `loyalty.promotion`). A sale line's
 * `adjustments: [{source, reference, amount_minor}]` say where its
 * discount came from.
 *
 * Each source registers a synchronous verifier: given the sale (company,
 * branch, customer, currency, sold_at, lines with item, qty and unit
 * price, extensions), it answers how much it vouches for on each line
 * (`[line_id => minor units]`). Only that much is not a manual discount;
 * whatever a till claims above it is treated as a manual discount
 * (`pos.discount.give`, else flagged `discount_unauthorised`).
 */
class DiscountSources
{
    /** @var array<string, array{module: string, verifier: Closure}> */
    private array $sources = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /** @param Closure(array<string, mixed>): array<string, string> $verifier */
    public function register(string $source, string $module, Closure $verifier): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $source) !== 1) {
            throw new InvalidArgumentException("Invalid discount source [{$source}].");
        }

        $this->sources[$source] = ['module' => $module, 'verifier' => $verifier];
    }

    /** Whether $source is registered by a module active in the current tenant. */
    public function vouches(string $source): bool
    {
        return isset($this->sources[$source]) && $this->modules->isActive($this->sources[$source]['module']);
    }

    /**
     * What an active $source vouches for on each line of $sale (minor
     * units, digit strings); [] when it is not active.
     *
     * @param  array<string, mixed>  $sale
     * @return array<string, string>
     */
    public function verify(string $source, array $sale): array
    {
        return $this->vouches($source) ? ($this->sources[$source]['verifier'])($sale) : [];
    }
}
