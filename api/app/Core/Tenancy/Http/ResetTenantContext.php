<?php

namespace App\Core\Tenancy\Http;

use App\Core\Api\ApiKeyContext;
use App\Core\Api\ApiScopes;
use App\Core\Audit\AuditContext;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * First global middleware: no request starts with a tenant, an
 * authenticated user or audit context (device, location, ...) left over
 * from a previous request on a reused connection or worker (TEN-01). The tenant is then set only from the
 * resolved token. The API key of a previous request and the key scopes
 * memoised for it are forgotten too (ADR 020).
 */
class ResetTenantContext
{
    public function __construct(
        private TenantContext $context,
        private AuditContext $audit,
        private ApiKeyContext $apiKey,
        private ApiScopes $apiScopes,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->set(null);
        Auth::forgetGuards();
        $this->audit->reset();
        $this->apiKey->reset();
        $this->apiScopes->flush();

        return $next($request);
    }
}
