<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Http\Lists\TenderList;
use Modules\Procurement\Http\Requests\AddBidderRequest;
use Modules\Procurement\Http\Requests\CommitteeCandidatesRequest;
use Modules\Procurement\Http\Requests\DocumentActionRequest;
use Modules\Procurement\Http\Requests\ListTendersRequest;
use Modules\Procurement\Http\Requests\ShowDocumentRequest;
use Modules\Procurement\Http\Requests\StoreTenderRequest;
use Modules\Procurement\Http\Requests\UpdateTenderRequest;
use Modules\Procurement\Http\Resources\TenderResource;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Tenders\Bidders;
use Modules\Procurement\Tenders\Opening;
use Modules\Procurement\Tenders\Tenders;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PRC-06 to PRC-10: tenders, their bidders and the committee opening. */
class TenderController
{
    public function __construct(
        private readonly Tenders $tenders,
        private readonly Bidders $bidders,
        private readonly Opening $opening,
    ) {}

    public function index(ListTendersRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Tender::query()->with(TenderList::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return TenderResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($procurementTender);
    }

    public function store(StoreTenderRequest $request): JsonResponse
    {
        return $this->detail($this->tenders->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(UpdateTenderRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($this->tenders->update($procurementTender, $request->validated()));
    }

    public function publish(DocumentActionRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($this->tenders->publish($procurementTender));
    }

    public function cancel(DocumentActionRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($this->tenders->cancel($procurementTender, $request->user(), (string) $request->validated('reason')));
    }

    public function addBidder(AddBidderRequest $request, Tender $procurementTender): JsonResponse
    {
        $this->bidders->add($procurementTender, $request->validated(), $request->user());

        return $this->detail($procurementTender)->response()->setStatusCode(201);
    }

    /** PRC-10: one opener's confirmation; the second opens the bids. */
    public function open(DocumentActionRequest $request, Tender $procurementTender): TenderResource
    {
        return $this->detail($this->opening->confirm($procurementTender, $request->user()));
    }

    public function resend(DocumentActionRequest $request, Bidder $procurementBidder): TenderResource
    {
        $this->bidders->resend($procurementBidder);

        return $this->detail(Tender::query()->findOrFail($procurementBidder->tender_id));
    }

    public function removeBidder(DocumentActionRequest $request, Bidder $procurementBidder): TenderResource
    {
        $this->bidders->remove($procurementBidder);

        return $this->detail(Tender::query()->findOrFail($procurementBidder->tender_id));
    }

    /** PRC-06: who may sit on the committee at the branch (openers hold `procurement.tender.open`, evaluators `.evaluate`). */
    public function candidates(CommitteeCandidatesRequest $request, Branch $branch, ScopeResolver $scopes): JsonResponse
    {
        $roles = $request->validated('role') === null ? ['opener', 'evaluator'] : [$request->validated('role')];
        $users = User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->orderBy('id')->get()
            ->map(function (User $user) use ($roles, $scopes, $branch) {
                $can = array_values(array_filter($roles, fn (string $role) => $scopes->can($user, $role === 'opener' ? 'procurement.tender.open' : 'procurement.tender.evaluate', Scope::branch($branch->id))));

                return $can === [] ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'roles' => $can];
            })->filter()->values()->all();

        return response()->json(['data' => $users]);
    }

    private function detail(Tender $tender): TenderResource
    {
        return TenderResource::make($tender->refresh()->load(TenderList::RELATIONS))->detail();
    }
}
