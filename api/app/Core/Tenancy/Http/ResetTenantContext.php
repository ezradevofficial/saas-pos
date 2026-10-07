<?php

namespace App\Core\Tenancy\Http;

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
 * resolved token.
 */
class ResetTenantContext
{
    public function __construct(
        private TenantContext $context,
        private AuditContext $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->set(null);
        Auth::forgetGuards();
        $this->audit->reset();

        return $next($request);
    }
}
