<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Exports\ListExport;
use App\Core\Identity\Http\Requests\EndSessionRequest;
use App\Core\Identity\Http\Requests\ListSessionsRequest;
use App\Core\Identity\Http\Requests\SignOutRequest;
use App\Core\Identity\Http\Resources\SessionResource;
use App\Core\Identity\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AUTH-09: the signed-in user's own sessions (listed, sorted and exported
 * as SessionList, EXP-01). Tokens are global rows, so
 * every query goes through $user->tokens().
 */
class SessionController
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(ListSessionsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->user()->tokens()->getQuery();
        $request->applySort($request->applySearch($query, ['name' => 'name', 'ip' => 'ip', 'user_agent' => 'user_agent']));

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        // Every session unless paging is asked for (ListSessionsRequest).
        return SessionResource::collection($request->wantsPage() ? $query->paginate($request->perPage())->withQueryString() : $query->get());
    }

    public function destroy(EndSessionRequest $request, string $id): Response
    {
        $token = Str::isUuid($id) ? $request->user()->tokens()->whereKey($id)->first() : null;

        abort_if($token === null, 404);

        $this->revoke($request, $token);

        return response()->noContent();
    }

    public function signOut(SignOutRequest $request): Response
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
