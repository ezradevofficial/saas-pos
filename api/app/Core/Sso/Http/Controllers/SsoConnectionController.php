<?php

namespace App\Core\Sso\Http\Controllers;

use App\Core\Sso\Http\Requests\SaveSsoConnectionRequest;
use App\Core\Sso\Http\Requests\SsoManageRequest;
use App\Core\Sso\Http\Requests\TestSsoConnectionRequest;
use App\Core\Sso\Saml\SamlMetadata;
use App\Core\Sso\SsoConnections;
use App\Core\Sso\SsoFlows;
use Illuminate\Http\JsonResponse;

/**
 * AUTH-11, ADR 023: add, change, enable, disable, archive and test a
 * connection (`core.sso.manage`, tenant scope). Audited as
 * `core.sso.connection_*`.
 */
class SsoConnectionController
{
    public function __construct(private readonly SsoConnections $connections) {}

    public function store(SaveSsoConnectionRequest $request, SamlMetadata $metadata): JsonResponse
    {
        $connection = $this->connections->create($request->user(), [
            'provider' => $request->provider(),
            'name' => $request->validated('name'),
            'settings' => $request->connectionSettings($metadata),
            'client_secret' => $request->validated('client_secret'),
        ]);

        return response()->json(['data' => SsoController::connection($connection)], 201);
    }

    public function update(SaveSsoConnectionRequest $request, SamlMetadata $metadata): JsonResponse
    {
        $data = ['settings' => $request->connectionSettings($metadata)];

        foreach (['name', 'client_secret'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->validated($field);
            }
        }

        return response()->json(['data' => SsoController::connection($this->connections->update($request->connection(), $data))]);
    }

    public function enable(SsoManageRequest $request): JsonResponse
    {
        return response()->json(['data' => SsoController::connection($this->connections->enable($request->connection()))]);
    }

    public function disable(SsoManageRequest $request): JsonResponse
    {
        return response()->json(['data' => SsoController::connection($this->connections->disable($request->connection()))]);
    }

    public function archive(SsoManageRequest $request): JsonResponse
    {
        $this->connections->archive($request->connection());

        return response()->json(['data' => null]);
    }

    /** A test sign-in: the browser goes to the IdP and comes back to the settings page. */
    public function test(TestSsoConnectionRequest $request, SsoFlows $flows): JsonResponse
    {
        $started = $flows->startTest($request->connection(), $request->user(), $request->validated('origin'));

        return response()->json(['data' => ['redirect_url' => $started['redirect_url']]]);
    }
}
