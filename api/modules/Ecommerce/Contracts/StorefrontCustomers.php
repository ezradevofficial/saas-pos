<?php

namespace Modules\Ecommerce\Contracts;

use App\Core\Rbac\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Ecommerce\Customers\CustomerAuth;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Models\CustomerSession;

/**
 * A read contract for other modules that serve storefront customers (Salon's public booking page, SAL-15): who a
 * signed-in customer's bearer token is, as the id of their core party. It reads, never changes: a session is neither
 * refreshed nor created. Answers only while E-commerce is active for the tenant and only for a live session (not revoked,
 * not expired) of a customer who is not archived; null for anything else, including every non-customer token.
 */
final class StorefrontCustomers
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /** The party id of the customer the token belongs to, in the current tenant, or null. */
    public function partyOf(string $token, string $companyId): ?string
    {
        if (! $this->modules->isActive(EcommerceServiceProvider::MODULE) || ! str_starts_with($token, CustomerAuth::PREFIX) || strlen($token) !== strlen(CustomerAuth::PREFIX) + 48) {
            return null;
        }

        $session = CustomerSession::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', CarbonImmutable::now())->first();
        $customer = $session === null ? null : Customer::query()->whereKey($session->customer_id)->whereNull('archived_at')->first();
        $partyId = $customer?->party_id;

        return $customer !== null && $customer->company_id === $companyId && is_string($partyId) && Str::isUuid($partyId) ? $partyId : null;
    }
}
