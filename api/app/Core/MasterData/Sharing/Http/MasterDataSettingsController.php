<?php

namespace App\Core\MasterData\Sharing\Http;

use App\Core\MasterData\Sharing\MasterDataSharing;
use Illuminate\Http\JsonResponse;

/** TEN-08: the sharing mode of each master data type, and switching it. */
class MasterDataSettingsController
{
    public function __construct(private readonly MasterDataSharing $sharing) {}

    public function show(MasterDataSettingsRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => array_values($this->sharing->all())]);
    }

    public function update(UpdateMasterDataSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->sharing->switch(
            $data['data_type'], $data['mode'], $data['assign_to_company_id'] ?? null, (bool) ($data['confirm'] ?? false),
        );

        return new JsonResponse(['data' => array_values($this->sharing->all()), 'meta' => $result]);
    }
}
