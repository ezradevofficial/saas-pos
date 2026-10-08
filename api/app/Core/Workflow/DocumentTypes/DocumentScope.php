<?php

namespace App\Core\Workflow\DocumentTypes;

use App\Core\Rbac\Scope;

/**
 * Where a document belongs (WF-01, WF-08, APR-02): its company, branch and
 * location, any of them unknown. Stage permissions and approvers are
 * resolved at the narrowest known level; the business calendar uses the
 * company's time zone, hours and country.
 */
final class DocumentScope
{
    public function __construct(
        public readonly ?string $companyId = null,
        public readonly ?string $branchId = null,
        public readonly ?string $locationId = null,
    ) {}

    /** The narrowest scope, for ScopeResolver checks (the tenant when nothing is known). */
    public function scope(): Scope
    {
        return match (true) {
            $this->locationId !== null => Scope::location($this->locationId),
            $this->branchId !== null => Scope::branch($this->branchId),
            $this->companyId !== null => Scope::company($this->companyId),
            default => Scope::tenant(),
        };
    }

    /**
     * The scopes covering the document, as `type:id`, the tenant first.
     *
     * @return list<string>
     */
    public function chain(string $tenantId): array
    {
        return array_values(array_filter([
            'tenant:'.$tenantId,
            $this->companyId === null ? null : 'company:'.$this->companyId,
            $this->branchId === null ? null : 'branch:'.$this->branchId,
            $this->locationId === null ? null : 'location:'.$this->locationId,
        ]));
    }

    /** @return array{company_id: ?string, branch_id: ?string, location_id: ?string} */
    public function toArray(): array
    {
        return ['company_id' => $this->companyId, 'branch_id' => $this->branchId, 'location_id' => $this->locationId];
    }
}
