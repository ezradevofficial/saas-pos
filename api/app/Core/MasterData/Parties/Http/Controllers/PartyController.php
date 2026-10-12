<?php

namespace App\Core\MasterData\Parties\Http\Controllers;

use App\Core\CustomFields\CustomFieldLists;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\CustomFields\Entities\PartyEntity;
use App\Core\Exports\ListExport;
use App\Core\MasterData\Duplicates\DuplicateFinder;
use App\Core\MasterData\Parties\Http\Requests\ListPartiesRequest;
use App\Core\MasterData\Parties\Http\Requests\PartyActionRequest;
use App\Core\MasterData\Parties\Http\Requests\PartyRequest;
use App\Core\MasterData\Parties\Http\Requests\StorePartyRequest;
use App\Core\MasterData\Parties\Http\Requests\UpdatePartyRequest;
use App\Core\MasterData\Parties\Http\Resources\PartyResource;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\MasterData\Parties\PartyWriter;
use App\Core\MasterData\Support\TextArray;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MD-01: parties (customers, suppliers, contacts, employee links), shared
 * or per company by their roles' sharing mode (TEN-08). Create and update
 * answer `meta.possible_duplicates` (MD-06), never blocking. Archived,
 * never deleted (TEN-06); every change audited (MD-07).
 */
class PartyController
{
    public function __construct(
        private readonly PartyPolicy $policy,
        private readonly DuplicateFinder $duplicates,
        private readonly CustomFieldWriter $customFields,
        private readonly CustomFieldLists $customLists,
        private readonly PartyWriter $writer,
    ) {}

    public function index(ListPartiesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Party::query();
        $companies = $this->policy->listableCompanies($request->user());

        if ($companies !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        if ($request->filled('role')) {
            $query->whereRaw('roles @> ?::text[]', [TextArray::format([$request->validated('role')])]);
        }

        if ($request->filled('tag')) {
            $query->whereRaw('tags @> ?::text[]', [TextArray::format([Str::lower(trim($request->validated('tag')))])]);
        }

        // CF-03: `?custom[key]=` filters (validated: known, visible fields).
        $this->customLists->apply($query, PartyEntity::KEY, $request->validated('custom'));

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $this->search($query, $search, $request);
        }

        // Most similar names first when searching (and names are visible), unless a sort is asked for.
        $relevance = $search === '' || $request->hidesField('name') ? null : fn (Builder $q) => $q->orderByRaw('similarity(name, ?) desc', [$search]);
        $request->applySort($request->applyStatus($query), $relevance);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PartyResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StorePartyRequest $request): JsonResponse
    {
        return $this->respond($request, $this->writer->create($request->validated()), 201, duplicates: true);
    }

    public function show(PartyRequest $request, Party $party): JsonResponse
    {
        return $this->respond($request, $party);
    }

    public function update(UpdatePartyRequest $request, Party $party): JsonResponse
    {
        return $this->respond($request, $this->writer->update($party, $request->validated(), $request->user()), duplicates: true);
    }

    public function archive(PartyActionRequest $request, Party $party): JsonResponse
    {
        if (! $party->isArchived()) {
            $party->archive();
        }

        return $this->respond($request, $party);
    }

    public function restore(PartyActionRequest $request, Party $party): JsonResponse
    {
        if ($party->isArchived()) {
            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($party) {
                // CF-02: a restored party takes its unique custom values back.
                $this->customFields->assertRestorable(PartyEntity::KEY, $party);
                $party->restore();
            });
        }

        return $this->respond($request, $party);
    }

    /**
     * Name or legal name (contains, or trigram-similar), the tax ID, or a
     * phone containing the digits typed.
     */
    private function search(Builder $query, string $search, ListPartiesRequest $request): void
    {
        $like = '%'.addcslashes($search, '\\%_').'%';
        $digits = preg_replace('/\D/', '', $search);
        $taxId = Str::upper(preg_replace('/\s+/u', '', $search));
        // RBAC-05: a field the user can't see is never matched, or the
        // results would reveal its value.
        $visible = fn (string $field) => ! $request->hidesField($field);

        $query->where(function (Builder $q) use ($search, $like, $digits, $taxId, $visible) {
            $q->whereRaw('false');

            if ($visible('name')) {
                $q->orWhere('name', 'ilike', $like)->orWhereRaw('name % ?', [$search]);
            }

            if ($visible('legal_name')) {
                $q->orWhere('legal_name', 'ilike', $like);
            }

            if ($visible('tax_id')) {
                $q->orWhere('tax_id', $taxId);
            }

            if (strlen($digits) >= 4 && $visible('phones')) {
                $q->orWhereRaw('phones::text like ?', ['%'.$digits.'%']);
            }
        });
    }

    private function respond(Request $request, Party $party, int $status = 200, bool $duplicates = false): JsonResponse
    {
        $resource = PartyResource::make($party->refresh());

        if ($duplicates) {
            $resource->additional(['meta' => ['possible_duplicates' => $this->duplicates->forParty($party, $request->user())]]);
        }

        return $resource->response()->setStatusCode($status);
    }
}
