<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Identity\Http\Requests\UpdateMeRequest;
use App\Core\Identity\Http\Resources\UserResource;
use Illuminate\Http\Request;

/** GET and PATCH me: the signed-in user's own profile. */
class MeController
{
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    public function update(UpdateMeRequest $request): UserResource
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return UserResource::make($user);
    }
}
