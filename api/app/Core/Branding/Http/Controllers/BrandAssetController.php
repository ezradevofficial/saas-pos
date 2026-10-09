<?php

namespace App\Core\Branding\Http\Controllers;

use App\Core\Branding\BrandAssets;
use App\Core\Branding\Http\Requests\BrandAssetRequest;
use App\Core\Branding\Http\Requests\StoreBrandAssetRequest;
use App\Core\Branding\Models\BrandAsset;
use Illuminate\Http\JsonResponse;

/**
 * BR-02, BR-04: the tenant's brand assets, newest first, with signed URLs
 * (GET), and uploading one (POST, audited as `core.branding.asset_add`).
 */
class BrandAssetController
{
    public function __construct(private readonly BrandAssets $assets) {}

    public function index(BrandAssetRequest $request): JsonResponse
    {
        $assets = BrandAsset::query()
            ->when($request->validated('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $assets->map(fn (BrandAsset $asset) => $this->present($asset))->values()]);
    }

    public function store(StoreBrandAssetRequest $request): JsonResponse
    {
        $asset = $this->assets->add($request->validated('kind'), $request->file('file'), $request->user());

        return response()->json(['data' => $this->present($asset)], 201);
    }

    /** @return array<string, mixed> */
    private function present(BrandAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'kind' => $asset->kind,
            'mime' => $asset->mime,
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'url' => $this->assets->url($asset),
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }
}
