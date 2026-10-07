<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Identity\Http\Requests\SignUpRequest;
use App\Core\Identity\Services\Challenges;
use App\Core\Identity\Services\SignUp;
use Illuminate\Http\JsonResponse;

/** POST auth/sign-up (AUTH-01). */
class SignUpController
{
    public function __invoke(SignUpRequest $request, SignUp $signUp): JsonResponse
    {
        ['challenge' => $challenge] = $signUp->handle($request->validated());

        return response()->json([
            'challenge_id' => $challenge->id,
            'destination_masked' => Challenges::maskDestination($challenge->channel, $challenge->destination),
        ], 201);
    }
}
