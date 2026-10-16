<?php

namespace App\Core\Api\Http\Controllers;

use App\Core\Api\Http\Requests\SandboxResetRequest;
use App\Core\Api\Sandbox\SandboxReset;
use Illuminate\Http\JsonResponse;

/** API-05: a developer sandbox's reset (people only; 404 for a live tenant). */
class SandboxController
{
    public function reset(SandboxResetRequest $request, SandboxReset $reset): JsonResponse
    {
        return new JsonResponse(['data' => ['archived' => $reset->reset($request->user())]]);
    }
}
