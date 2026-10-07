<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Identity\Http\Resources\SessionResource;
use App\Core\Identity\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AUTH-09: the signed-in user's own sessions. Tokens are global rows, so
 * every query goes through $user->tokens().
 */
class SessionController
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tokens = $request->user()->tokens()
            ->orderByRaw('coalesce(last_used_at, created_at) desc')
            ->get();

        return SessionResource::collection($tokens);
    }

    public function destroy(Request $request, string $id): Response
    {
        $token = Str::isUuid($id) ? $request->user()->tokens()->whereKey($id)->first() : null;

        abort_if($token === null, 404);

        $this->revoke($request, $token);

        return response()->noContent();
    }

    public function signOut(Request $request): Response
    {
        $this->revoke($request, $request->user()->currentAccessToken());

        return response()->noContent();
    }

    private function revoke(Request $request, PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($request, $token) {
            $this->auditor->record('auth.sign_out', $request->user(), null, [
                'token_id' => $token->getKey(),
                'device' => $token->name,
            ]);

            $token->delete();
        });
    }
}
