<?php

namespace App\Core\MasterData\Prices;

use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use App\Core\Rbac\FieldRules;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/**
 * Who sees and changes item prices (MD-03 follow-up, RBAC-04, RBAC-05).
 *
 * - Prices belong to a price list, so to a company: `core.price.view` (or
 *   `edit`) at the company, above it or beneath it reads them (a cashier
 *   at an outlet reads the prices of the outlet's company); `edit` at a
 *   scope covering the company changes them.
 * - RBAC-05: prices are the field `prices` of the `item` field rules
 *   resource. Hidden: price amounts are left out of items, price lists and
 *   history; hidden or read-only: prices cannot be changed (422
 *   `field_readonly`).
 */
class PriceAccess
{
    public const VIEW = 'core.price.view';

    public const EDIT = 'core.price.edit';

    /** The field rules resource and field that hide or freeze prices (RBAC-05). */
    public const FIELD_RULES = 'item';

    public const FIELD = 'prices';

    public function __construct(
        private readonly CompanyReach $reach,
        private readonly ScopeResolver $resolver,
        private readonly FieldRules $fieldRules,
    ) {}

    public function canView(User $user, string $companyId): bool
    {
        return $this->reach->reachesRecord($user, $companyId, [self::VIEW, self::EDIT]);
    }

    public function canEdit(User $user, string $companyId): bool
    {
        return $this->resolver->can($user, self::EDIT, Scope::company($companyId));
    }

    /**
     * The companies whose prices the user reads, or null for all of the tenant's.
     *
     * @return list<string>|null
     */
    public function companies(User $user): ?array
    {
        return $this->reach->companyIds($user, [self::VIEW, self::EDIT]);
    }

    /** RBAC-05: prices hidden from the user by field rules. */
    public function hidden(User $user): bool
    {
        return in_array(self::FIELD, $this->fieldRules->for($user, self::FIELD_RULES)['hidden'], true);
    }

    /** RBAC-05: prices hidden from, or read-only for, the user. */
    public function frozen(User $user): bool
    {
        $rules = $this->fieldRules->for($user, self::FIELD_RULES);

        return in_array(self::FIELD, [...$rules['hidden'], ...$rules['readonly']], true);
    }
}
