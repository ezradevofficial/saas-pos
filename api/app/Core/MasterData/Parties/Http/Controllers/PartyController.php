<?php

namespace App\Core\MasterData\Parties\Http\Controllers;

use App\Core\MasterData\Duplicates\DuplicateFinder;
use App\Core\MasterData\Parties\Http\Requests\ListPartiesRequest;
use App\Core\MasterData\Parties\Http\Requests\PartyActionRequest;
use App\Core\MasterData\Parties\Http\Requests\PartyRequest;
use App\Core\MasterData\Parties\Http\Requests\PartyRules;
use App\Core\MasterData\Parties\Http\Requests\StorePartyRequest;
use App\Core\MasterData\Parties\Http\Requests\UpdatePartyRequest;
use App\Core\MasterData\Parties\Http\Resources\PartyResource;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\MasterData\Parties\PartyRoles;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Support\TextArray;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        private readonly MasterDataSharing $sharing,
        private readonly DuplicateFinder $duplicates,
    ) {}

    public function index(ListPartiesRequest $request): AnonymousResourceCollection
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

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $this->search($query, $search);
        }

        return PartyResource::collection(
            $request->applyStatus($query)->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString(),
        );
    }

    public function store(StorePartyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $companyId = PartyRules::companyId($data, null) ?: null;

        $attributes = PartyRules::attributes($data, $companyId);

        $party = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($attributes, $companyId) {
            $this->sharing->lockForWrite(PartyRoles::dataTypes($attributes['roles']));
            $this->assertModeUnchanged($attributes['roles'], $companyId);

            return Party::create(['company_id' => $companyId, ...$attributes]);
        });

        return $this->respond($request, $party, 201, duplicates: true);
    }

    public function show(PartyRequest $request, Party $party): JsonResponse
    {
        return $this->respond($request, $party);
    }

    public function update(UpdatePartyRequest $request, Party $party): JsonResponse
    {
        $data = $request->validated();

        $party = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($party, $data) {
            // Lock order, as in MasterDataSharing::switch: the sharing locks
            // first (every party type: the stored roles are only known once
            // the row is read), then the row. Never the other way round.
            $this->sharing->lockForWrite(PartyRoles::PARTY_DATA_TYPES);
            $party = Party::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();
            $companyId = PartyRules::companyId($data, $party) ?: null;
            $attributes = PartyRules::attributes($data, $companyId);
            $this->assertModeUnchanged($attributes['roles'] ?? $party->roles, $companyId);

            $party->fill(['company_id' => $companyId, ...$attributes])->save();

            return $party;
        });

        return $this->respond($request, $party, duplicates: true);
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
            $party->restore();
        }

        return $this->respond($request, $party);
    }

    /**
     * Name or legal name (contains, or trigram-similar), the tax ID, or a
     * phone containing the digits typed; most similar names first.
     */
    private function search(Builder $query, string $search): void
    {
        $like = '%'.addcslashes($search, '\\%_').'%';
        $digits = preg_replace('/\D/', '', $search);
        $taxId = Str::upper(preg_replace('/\s+/u', '', $search));

        $query->where(function (Builder $q) use ($search, $like, $digits, $taxId) {
            $q->where('name', 'ilike', $like)
                ->orWhere('legal_name', 'ilike', $like)
                ->orWhereRaw('name % ?', [$search])
                ->orWhere('tax_id', $taxId);

            if (strlen($digits) >= 4) {
                $q->orWhereRaw('phones::text like ?', ['%'.$digits.'%']);
            }
        })->orderByRaw('similarity(name, ?) desc', [$search]);
    }

    /**
     * Re-checked under the sharing lock: the mode may have changed since
     * the request was validated (TEN-08).
     *
     * @param  list<string>  $roles
     */
    private function assertModeUnchanged(array $roles, ?string $companyId): void
    {
        if (PartyRoles::perCompany($roles, $this->sharing) !== ($companyId !== null)) {
            throw ValidationException::withMessages(['company_id' => __('core.master_data.sharing_changed')]);
        }
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
