<?php

namespace App\Core\Sso\Http\Controllers;

use App\Core\Sso\Domains\SsoDomains;
use App\Core\Sso\Http\Requests\SsoManageRequest;
use App\Core\Sso\Http\Requests\StoreSsoDomainRequest;
use Illuminate\Http\JsonResponse;

/**
 * AUTH-11, ADR 023: the tenant's email domains (`core.sso.manage`, tenant
 * scope): claim one (answers the TXT record to create), check now, and
 * archive. Audited as `core.sso.domain_*`.
 */
class SsoDomainController
{
    public function __construct(private readonly SsoDomains $domains) {}

    public function store(StoreSsoDomainRequest $request): JsonResponse
    {
        return response()->json(['data' => SsoController::domain($this->domains->add($request->validated('domain'), $request->user()))], 201);
    }

    public function check(SsoManageRequest $request): JsonResponse
    {
        return response()->json(['data' => SsoController::domain($this->domains->retry($request->domain()))]);
    }

    public function archive(SsoManageRequest $request): JsonResponse
    {
        $this->domains->archive($request->domain());

        return response()->json(['data' => null]);
    }
}
