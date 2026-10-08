<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\Rbac\FieldRules;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/**
 * Who sees and acts on credit limit change requests (RBAC-04, RBAC-05):
 *
 * - see: `core.party.view` at a scope touching the request's company (a
 *   branch user sees their company's requests); the amounts only when the
 *   party's credit limit is not hidden from them by field rules;
 * - request: see the party, hold `core.credit_limit.request` at a scope
 *   touching the company the request is for, and have the credit limit
 *   neither hidden nor read-only (field rules);
 * - cancel a pending one: its requester, or a holder of the request
 *   permission at the company itself (a company or tenant role).
 */
class CreditLimitChangeAccess
{
    /** The party field rules (RBAC-05) the amounts follow. */
    public const FIELD_RULES = 'party';

    public const LIMIT_FIELDS = ['credit_limit_minor', 'credit_limit_currency'];

    public function __construct(
        private readonly CompanyReach $reach,
        private readonly ScopeResolver $resolver,
        private readonly PartyPolicy $parties,
        private readonly FieldRules $fieldRules,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->reach->anywhere($user, [CreditLimitChangeType::VIEW]);
    }

    public function view(User $user, CreditLimitChange $change): bool
    {
        return $change->tenant_id === $user->tenant_id
            && $this->reach->reachesRecord($user, $change->company_id, [CreditLimitChangeType::VIEW]);
    }

    /** @return list<string>|null the companies whose requests the user may list (null: all) */
    public function listableCompanies(User $user): ?array
    {
        return $this->reach->companyIds($user, [CreditLimitChangeType::VIEW]);
    }

    public function requestAnywhere(User $user): bool
    {
        return $this->reach->anywhere($user, [CreditLimitChangeType::REQUEST]);
    }

    public function request(User $user, Party $party, string $companyId): bool
    {
        return $this->parties->view($user, $party)
            && $this->reach->reachesRecord($user, $companyId, [CreditLimitChangeType::REQUEST])
            // L1, RBAC-05: a field the user may not see, or may not change, is not
            // theirs to ask to change either (a read-only limit would otherwise be
            // changed through the back door).
            && ! $this->hidesLimit($user)
            && ! $this->limitReadOnly($user);
    }

    /** @return list<string>|null the companies the user may request for (null: all) */
    public function requestCompanies(User $user): ?array
    {
        return $this->reach->companyIds($user, [CreditLimitChangeType::REQUEST]);
    }

    public function cancel(User $user, CreditLimitChange $change): bool
    {
        return $change->requested_by === $user->id
            || $this->resolver->can($user, CreditLimitChangeType::REQUEST, Scope::company($change->company_id));
    }

    /** RBAC-05: the party's credit limit is read-only for the user. */
    public function limitReadOnly(User $user): bool
    {
        return array_intersect(self::LIMIT_FIELDS, $this->fieldRules->for($user, self::FIELD_RULES)['readonly']) !== [];
    }

    /** RBAC-05: the party's credit limit is hidden from the user. */
    public function hidesLimit(User $user): bool
    {
        return array_intersect(self::LIMIT_FIELDS, $this->fieldRules->for($user, self::FIELD_RULES)['hidden']) !== [];
    }
}
