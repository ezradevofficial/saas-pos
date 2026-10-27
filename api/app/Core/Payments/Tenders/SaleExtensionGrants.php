<?php

namespace App\Core\Payments\Tenders;

use App\Core\Rbac\ModuleRegistry;
use Closure;

/**
 * FUEL-18, RBAC-04: a module that rings its own sales (fuel at the pumps,
 * Restaurant's bills) may let its permission stand in for `pos.sale.create`
 * on the sale lines it answers for. A module registers a grant: the key of
 * its sale `extensions` entry, the permission, and which of a sale's items
 * it covers (answered by the module from its own tables, so POS reads none
 * of them). An empty or missing extension grants nothing, a module that is
 * off for the tenant grants nothing, and a line the module does not cover
 * still needs `pos.sale.create`.
 */
class SaleExtensionGrants
{
    /** @var array<string, array{module: string, permission: string, covers: Closure}> */
    private array $grants = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /** @param Closure(list<string>): list<string> $covers the item ids (of those given) the module answers for */
    public function register(string $key, string $module, string $permission, Closure $covers): void
    {
        $this->grants[$key] = ['module' => $module, 'permission' => $permission, 'covers' => $covers];
    }

    /**
     * The items of a sale that still need `pos.sale.create`.
     *
     * @param  array<string, mixed>|null  $extensions  the sale's `extensions`
     * @param  list<string>  $itemIds  the sale's item ids
     * @param  Closure(string): bool  $can  whether the cashier holds a permission at the till's place
     * @return list<string>
     */
    public function uncovered(?array $extensions, array $itemIds, Closure $can): array
    {
        $left = array_values(array_unique($itemIds));

        foreach ($this->grants as $key => $grant) {
            if ($left === [] || ! is_array($extensions[$key] ?? null) || $extensions[$key] === [] || ! $this->modules->isActive($grant['module']) || ! $can($grant['permission'])) {
                continue;
            }

            $left = array_values(array_diff($left, ($grant['covers'])($left)));
        }

        return $left;
    }
}
