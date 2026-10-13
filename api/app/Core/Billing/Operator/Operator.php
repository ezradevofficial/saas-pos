<?php

namespace App\Core\Billing\Operator;

use App\Core\Http\ApiException;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;

/**
 * SUB-03: the operator, the platform's seller, modelled as one internal
 * tenant (`tenants.is_operator`, created by `platform:operator`) whose
 * companies are the selling entities per country. Its invoices, fiscal
 * settings and payment methods are ordinary tenant data, so eTIMS, the
 * DGI and M-Pesa work for it exactly as for customers.
 *
 * Its id comes from a security-definer function (ADR 002); work on its
 * data runs inside its context (run), never through the owner connection.
 */
class Operator
{
    private ?string $id = null;

    public function __construct(
        private readonly DueTenants $due,
        private readonly TenantContext $tenants,
    ) {}

    public function id(): ?string
    {
        return $this->id ??= $this->due->operator();
    }

    /** The operator tenant's id; 503 `billing_unavailable` until it is set up. */
    public function require(): string
    {
        return $this->id() ?? throw new ApiException(503, 'billing_unavailable', __('billing.errors.unavailable'));
    }

    public function is(?string $tenantId): bool
    {
        return $tenantId !== null && $tenantId === $this->id();
    }

    /**
     * Run $fn in the operator tenant's context.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        return $this->tenants->run($this->require(), $fn);
    }

    /** The operator's selling company in $country (inside the operator's context). */
    public function company(string $country): Company
    {
        $company = Company::query()->where('country', $country)->whereNull('archived_at')->orderBy('created_at')->first();

        return $company ?? throw new ApiException(503, 'billing_unavailable', __('billing.errors.no_seller', ['country' => $country]));
    }

    /** Forget the cached id (tests, and the command that creates the operator). */
    public function forget(): void
    {
        $this->id = null;
    }
}
