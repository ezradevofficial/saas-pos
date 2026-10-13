<?php

namespace App\Core\MasterData\Prices;

use App\Core\Rbac\ModuleRegistry;
use InvalidArgumentException;

/**
 * POS-07, LOY-11: modules that vouch for part of a line's discount (the
 * Loyalty module's promotions, `loyalty.promotion`). A sale line's
 * `adjustments: [{source, reference, amount_minor}]` say where its
 * discount came from; the part an active source vouches for is not a
 * manual discount (no `pos.discount.give` check). The source checks it
 * itself (re-evaluation on upload) and flags what differs.
 */
class DiscountSources
{
    /** @var array<string, string> source key => module */
    private array $sources = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function register(string $source, string $module): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $source) !== 1) {
            throw new InvalidArgumentException("Invalid discount source [{$source}].");
        }

        $this->sources[$source] = $module;
    }

    /** Whether $source is registered by a module active in the current tenant. */
    public function vouches(string $source): bool
    {
        return isset($this->sources[$source]) && $this->modules->isActive($this->sources[$source]);
    }
}
