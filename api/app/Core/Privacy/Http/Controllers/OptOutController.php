<?php

namespace App\Core\Privacy\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Privacy\Http\Requests\OptOutRequest;
use App\Core\Privacy\OptOuts;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * NFR-08 (KE DPA): GET and POST /api/v1/opt-out/{token}, a marketing
 * email's unsubscribe link. No sign-in: the signed token is the
 * credential. Answers the state and the delivery's language only, never
 * who the customer is. A bad, foreign or expired token is a 404.
 */
class OptOutController
{
    public const LIMITER = 'privacy-opt-out';

    public function __construct(private readonly OptOuts $optOuts, private readonly TenantContext $tenants) {}

    public function show(OptOutRequest $request, string $token): JsonResponse
    {
        return $this->answer(fn () => $this->optOuts->linkState($token));
    }

    public function store(OptOutRequest $request, string $token): JsonResponse
    {
        return $this->answer(fn () => $this->optOuts->useLink($token));
    }

    private function answer(callable $fn): JsonResponse
    {
        try {
            $state = $fn();
        } finally {
            $this->tenants->set(null);
        }

        if ($state === null) {
            throw new ApiException(404, 'opt_out_link_invalid', __('privacy.errors.opt_out_link_invalid'));
        }

        return response()->json(['data' => $state]);
    }
}
