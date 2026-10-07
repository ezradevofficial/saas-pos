<?php

namespace App\Core\Identity\Http\Controllers;

use App\Core\Identity\Http\Requests\UpdateMeRequest;
use App\Core\Identity\Http\Resources\MeResource;
use Illuminate\Http\Request;

/** GET and PATCH me: the signed-in user's own profile. */
class MeController
{
    public function show(Request $request): MeResource
    {
        return MeResource::make($request->user());
    }

    public function update(UpdateMeRequest $request): MeResource
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return MeResource::make($user);
    }
}
