<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Identity\Http\Requests\AcceptInvitationRequest;
use App\Core\Identity\Http\Responses\TokenResponse;
use App\Core\Identity\Services\Invitations;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public invitation endpoints (AUTH-05): what the invitee sees before
 * accepting, and accepting, which signs them in.
 */
class AcceptInvitationController
{
    public function __construct(private readonly Invitations $invitations) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $invitation = $this->invitations->open($token);

        return new JsonResponse([
            'tenant_name' => Tenant::findOrFail($invitation->tenant_id)->name,
            'name' => $invitation->name,
            'email' => $invitation->email,
            'phone' => $invitation->phone,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->invitations->accept(
            $request->invitation(),
            $request->validated('name'),
            $request->validated('password'),
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        return TokenResponse::make($token, $user, $request)->setStatusCode(201);
    }
}
