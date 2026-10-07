<?php

namespace App\Core\Tenancy\Http;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First global middleware: no request starts with a tenant left over from a
 * previous request on a reused connection or worker (TEN-01).
 */
class ResetTenantContext
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->set(null);

        return $next($request);
    }
}
