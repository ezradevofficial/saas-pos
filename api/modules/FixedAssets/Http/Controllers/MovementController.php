<?php

namespace Modules\FixedAssets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\FixedAssets\Documents\Disposals;
use Modules\FixedAssets\Documents\Revaluations;
use Modules\FixedAssets\Documents\Transfers;
use Modules\FixedAssets\Http\Requests\CancelDisposalRequest;
use Modules\FixedAssets\Http\Requests\CancelTransferRequest;
use Modules\FixedAssets\Http\Requests\ListDisposalsRequest;
use Modules\FixedAssets\Http\Requests\ListRevaluationsRequest;
use Modules\FixedAssets\Http\Requests\ListTransfersRequest;
use Modules\FixedAssets\Http\Requests\PostDisposalRequest;
use Modules\FixedAssets\Http\Requests\ShowDisposalRequest;
use Modules\FixedAssets\Http\Requests\ShowTransferRequest;
use Modules\FixedAssets\Http\Requests\StoreDisposalRequest;
use Modules\FixedAssets\Http\Requests\StoreRevaluationRequest;
use Modules\FixedAssets\Http\Requests\StoreTransferRequest;
use Modules\FixedAssets\Http\Resources\RecordResources;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Disposal;
use Modules\FixedAssets\Models\Revaluation;
use Modules\FixedAssets\Models\Transfer;

/** FA-14 to FA-17: transfers, disposals, revaluations and impairments of the assets the reader sees. */
class MovementController
{
    private const TRANSFER = ['asset', 'company', 'fromBranch', 'fromLocation', 'toBranch', 'toLocation', 'fromCustodianUser', 'toCustodianUser', 'fromCostCentre', 'toCostCentre', 'requester', 'decider'];

    private const DISPOSAL = ['asset', 'company', 'party', 'requester', 'decider', 'poster'];

    public function __construct(
        private readonly Transfers $transfers,
        private readonly Disposals $disposals,
        private readonly Revaluations $revaluations,
    ) {}

    public function transfers(ListTransfersRequest $request): AnonymousResourceCollection
    {
        $query = Transfer::query()->with(self::TRANSFER);
        $request->applyAssetFilters($query, 'transfer_date');
        $this->search($query, $request->validated('search'));

        return AssetController::page($query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->perPage())->withQueryString(),
            fn (Transfer $transfer) => RecordResources::transfer($request, $transfer));
    }

    public function storeTransfer(StoreTransferRequest $request, Asset $faAsset): JsonResponse
    {
        $transfer = $this->transfers->request($faAsset, $request->validated(), $request->user());

        return response()->json(['data' => RecordResources::transfer($request, $transfer->load(self::TRANSFER))], 201);
    }

    public function transfer(ShowTransferRequest $request, Transfer $faTransfer): JsonResponse
    {
        return response()->json(['data' => RecordResources::transfer($request, $faTransfer->load(self::TRANSFER))]);
    }

    public function cancelTransfer(CancelTransferRequest $request, Transfer $faTransfer): JsonResponse
    {
        $transfer = $this->transfers->cancel($faTransfer, $request->user(), (string) $request->validated('reason'));

        return response()->json(['data' => RecordResources::transfer($request, $transfer->load(self::TRANSFER))]);
    }

    public function disposals(ListDisposalsRequest $request): AnonymousResourceCollection
    {
        $query = Disposal::query()->with(self::DISPOSAL);
        $request->applyAssetFilters($query, 'disposal_date');
        $this->search($query, $request->validated('search'));

        return AssetController::page($query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->perPage())->withQueryString(),
            fn (Disposal $disposal) => RecordResources::disposal($request, $disposal));
    }

    public function storeDisposal(StoreDisposalRequest $request, Asset $faAsset): JsonResponse
    {
        $disposal = $this->disposals->request($faAsset, $request->validated(), $request->user());

        return response()->json(['data' => RecordResources::disposal($request, $disposal->load(self::DISPOSAL))], 201);
    }

    public function disposal(ShowDisposalRequest $request, Disposal $faDisposal): JsonResponse
    {
        return response()->json(['data' => RecordResources::disposal($request, $faDisposal->load(self::DISPOSAL))]);
    }

    public function cancelDisposal(CancelDisposalRequest $request, Disposal $faDisposal): JsonResponse
    {
        $disposal = $this->disposals->cancel($faDisposal, $request->user(), (string) $request->validated('reason'));

        return response()->json(['data' => RecordResources::disposal($request, $disposal->load(self::DISPOSAL))]);
    }

    public function postDisposal(PostDisposalRequest $request, Disposal $faDisposal): JsonResponse
    {
        $disposal = $this->disposals->post($faDisposal, $request->user());

        return response()->json(['data' => RecordResources::disposal($request, $disposal->load(self::DISPOSAL))]);
    }

    public function revaluations(ListRevaluationsRequest $request): AnonymousResourceCollection
    {
        $query = Revaluation::query()->with(['asset', 'poster']);
        $request->applyAssetFilters($query, 'revalued_on');

        return AssetController::page($query->orderByDesc('revalued_on')->orderByDesc('created_at')->paginate($request->perPage())->withQueryString(),
            fn (Revaluation $revaluation) => RecordResources::revaluation($request, $revaluation));
    }

    public function storeRevaluation(StoreRevaluationRequest $request, Asset $faAsset): JsonResponse
    {
        $revaluation = $this->revaluations->post($faAsset, $request->validated(), $request->user());

        return response()->json(['data' => RecordResources::revaluation($request, $revaluation->load(['asset', 'poster']))], 201);
    }

    private function search($query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($q) => $q->where('number', 'ilike', $like)
                ->orWhereIn('asset_id', fn ($a) => $a->select('id')->from('fa_assets')->where('number', 'ilike', $like)->orWhere('name', 'ilike', $like)));
        }
    }
}
