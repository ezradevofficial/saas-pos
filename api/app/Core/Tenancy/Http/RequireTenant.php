<?php

namespace App\Core\Tenancy\Http;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API group, after auth: refuse the request when no tenant was resolved (TEN-01).
 */
class RequireTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->context->id() === null, 401);

        return $next($request);
    }
}
