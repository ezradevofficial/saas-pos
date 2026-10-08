<?php

namespace App\Core\CountryPacks\Http\Controllers;

use App\Core\CountryPacks\Http\Requests\CountryPackViewRequest;
use App\Core\CountryPacks\Http\Resources\CountryPackResource;
use App\Core\CountryPacks\Models\CountryPack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** CP-01, CP-03: the published country packs (global, read-only): each pack's version in force and its earlier versions. */
class CountryPackController
{
    public function index(CountryPackViewRequest $request): AnonymousResourceCollection
    {
        return CountryPackResource::collection(
            CountryPack::query()->orderBy('code')->orderByDesc('version')->get()->unique('code')->values(),
        );
    }

    public function show(CountryPackViewRequest $request, string $countryPack): JsonResponse
    {
        $pack = CountryPack::latest($countryPack) ?? abort(404);
        $pack->load('taxCodes');

        $versions = CountryPack::query()->where('code', $countryPack)->orderByDesc('version')->get()
            ->map(fn (CountryPack $version) => [
                'version' => $version->version,
                'published_at' => $version->published_at->toIso8601String(),
                'changes' => $version->summary['changes'] ?? null,
            ])->all();

        return CountryPackResource::make($pack)->additional(['meta' => ['versions' => $versions]])->response();
    }
}
