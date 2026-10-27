<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Pharmacy\Http\Requests\IssueRecallRequest;
use Modules\Pharmacy\Http\Requests\LiftRecallRequest;
use Modules\Pharmacy\Http\Requests\ListRecallsRequest;
use Modules\Pharmacy\Http\Requests\ShowRecallRequest;
use Modules\Pharmacy\Http\Resources\RecallResource;
use Modules\Pharmacy\Models\Recall;
use Modules\Pharmacy\Recalls\Recalls;

/** PHA-22, PHA-23: recalls and the customers who bought the recalled batch. */
class RecallController
{
    public const RELATIONS = ['item', 'issuer', 'lifter'];

    public function __construct(private readonly Recalls $recalls) {}

    public function index(ListRecallsRequest $request): AnonymousResourceCollection
    {
        $query = Recall::query()->with(self::RELATIONS)->withCount('lots')->orderByDesc('issued_at')->orderBy('id');

        if ($request->filled('status') && $request->validated('status') !== 'all') {
            $query->where('status', $request->validated('status'));
        }

        if ($request->filled('item_id')) {
            $query->where('item_id', $request->validated('item_id'));
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($q) => $q->where('batch_number', 'ilike', $like)->orWhere('reference', 'ilike', $like));
        }

        return RecallResource::collection($query->paginate((int) $request->validated('per_page', ListRequest::PER_PAGE))->withQueryString());
    }

    public function show(ShowRecallRequest $request, Recall $pharmacyRecall): RecallResource
    {
        return RecallResource::make($pharmacyRecall->load(self::RELATIONS)->loadCount('lots'));
    }

    public function store(IssueRecallRequest $request): JsonResponse
    {
        $recall = $this->recalls->issue($request->validated('item_id'), $request->validated('batch_number'), $request->validated('reason'), $request->validated('reference'), $request->user());

        return RecallResource::make($recall->load(self::RELATIONS)->loadCount('lots'))->response()->setStatusCode(201);
    }

    public function lift(LiftRecallRequest $request, Recall $pharmacyRecall): RecallResource
    {
        $recall = $this->recalls->lift($pharmacyRecall, (string) $request->validated('reason'), $request->user());

        return RecallResource::make($recall->load(self::RELATIONS)->loadCount('lots'));
    }

    /** PHA-23: who bought the recalled batch. */
    public function customers(ShowRecallRequest $request, Recall $pharmacyRecall): JsonResponse
    {
        return new JsonResponse(['data' => $this->recalls->customers($pharmacyRecall)]);
    }
}
