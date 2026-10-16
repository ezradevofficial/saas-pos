<?php

namespace Modules\FixedAssets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Assets\Documents;
use Modules\FixedAssets\Http\Requests\ArchiveDocumentRequest;
use Modules\FixedAssets\Http\Requests\DownloadDocumentRequest;
use Modules\FixedAssets\Http\Requests\ShowAssetRequest;
use Modules\FixedAssets\Http\Requests\StoreDocumentRequest;
use Modules\FixedAssets\Http\Resources\RecordResources;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\AssetDocument;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FA-06: an asset's documents. */
class DocumentController
{
    public function __construct(private readonly Documents $documents) {}

    public function index(ShowAssetRequest $request, Asset $faAsset): JsonResponse
    {
        $rows = AssetDocument::query()->with('uploader')->where('asset_id', $faAsset->id)->whereNull('archived_at')->orderByDesc('created_at')->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (AssetDocument $document) => RecordResources::document($document))->all()]);
    }

    public function store(StoreDocumentRequest $request, Asset $faAsset): JsonResponse
    {
        $document = $this->documents->upload($faAsset, $request->file('file'), $request->user());

        return response()->json(['data' => RecordResources::document($document->load('uploader'))], 201);
    }

    public function download(DownloadDocumentRequest $request, AssetDocument $faDocument): StreamedResponse
    {
        abort_if($faDocument->archived_at !== null, 404);

        return $this->documents->download($faDocument);
    }

    public function archive(ArchiveDocumentRequest $request, AssetDocument $faDocument): JsonResponse
    {
        return response()->json(['data' => RecordResources::document($this->documents->archive($faDocument, $request->user())->load('uploader'))]);
    }
}
