<?php

namespace App\Core\Api\Http\Controllers;

use App\Core\Api\Http\Requests\ApiKeyRequest;
use App\Core\Api\Http\Requests\SandboxResetRequest;
use App\Core\Api\Sandbox\Sandbox;
use App\Core\Api\Sandbox\SandboxReset;
use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** API-05: a developer sandbox's reset and outbox (people only; 404 for a live tenant). */
class SandboxController
{
    /** The latest messages the sandbox would have sent (email, SMS, WhatsApp), newest first. */
    public function outbox(ApiKeyRequest $request, Sandbox $sandbox): JsonResponse
    {
        if (! $sandbox->active()) {
            throw new ApiException(404, 'not_sandbox', __('api.errors.not_sandbox'));
        }

        $rows = DB::connection(TenantContext::CONNECTION)->table('sandbox_outbox')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(200)
            ->get(['id', 'channel', 'recipient', 'subject', 'body', 'source', 'created_at'])
            ->map(fn ($row) => [...(array) $row, 'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String()]);

        return new JsonResponse(['data' => $rows->all()]);
    }

    public function reset(SandboxResetRequest $request, SandboxReset $reset): JsonResponse
    {
        return new JsonResponse(['data' => ['archived' => $reset->reset($request->user())]]);
    }
}
