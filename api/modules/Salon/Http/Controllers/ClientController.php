<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\JsonResponse;
use Modules\Salon\Clients\Clients;
use Modules\Salon\Http\Requests\ClientRequest;
use Modules\Salon\Http\Requests\CreateClientRequest;
use Modules\Salon\Http\Requests\SaveClientRequest;
use Modules\Salon\Http\Requests\ViewRequest;
use Modules\Salon\Http\Resources\Views;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Client;
use Modules\Salon\Support\Parties;

/** SAL-25, NFR-08: clients (customer parties with a salon profile) and their visits. */
class ClientController
{
    public function __construct(
        private readonly Clients $clients,
        private readonly Parties $parties,
        private readonly Views $views,
        private readonly ScopeResolver $resolver,
    ) {}

    /** Customers of the company by name, phone or email (a page of 50), each with whether a profile with alerts exists. */
    public function index(ViewRequest $request): JsonResponse
    {
        $companyId = (string) $request->input('company_id');
        $search = trim((string) $request->input('search', ''));
        $query = Party::query()->whereNull('archived_at')->whereJsonContains('roles', 'customer')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->orderBy('name')->orderBy('id');

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhereRaw('phones::text ilike ?', [$like])->orWhereRaw('emails::text ilike ?', [$like]));
        }

        $page = $query->limit(50)->get();
        $profiles = Client::query()->whereIn('party_id', $page->modelKeys())->get()->keyBy('party_id');

        return response()->json(['data' => $page->map(fn (Party $p) => $this->views->client($p, $profiles->get($p->id), $request, $companyId))->values()->all()]);
    }

    public function store(CreateClientRequest $request): JsonResponse
    {
        $data = $request->validated();
        $party = $this->clients->party($data['company_id'], $data['name'], $data['phone'] ?? null, $data['email'] ?? null);
        $client = $this->clients->save($party, $data['company_id'], [], $request->user(), false);

        return response()->json(['data' => $this->views->client($party, $client, $request, $data['company_id'])], 201);
    }

    public function show(ClientRequest $request, Party $party): JsonResponse
    {
        $companyId = (string) $request->input('company_id');
        $this->parties->customer($party->id, $companyId, 'party');

        return response()->json(['data' => $this->views->client($party, $this->clients->profile($party), $request, $companyId)]);
    }

    public function update(SaveClientRequest $request, Party $party): JsonResponse
    {
        $companyId = (string) $request->input('company_id');
        $this->parties->customer($party->id, $companyId, 'party');
        $notes = $this->resolver->can($request->user(), 'salon.client.notes', Scope::company($companyId));
        $client = $this->clients->save($party, $companyId, $request->validated(), $request->user(), $notes);

        return response()->json(['data' => $this->views->client($party, $client, $request, $companyId)]);
    }

    /** The client's visits: appointments with their services, staff and, for those who may, the formula used. */
    public function visits(ClientRequest $request, Party $party): JsonResponse
    {
        $companyId = (string) $request->input('company_id');
        $this->parties->customer($party->id, $companyId, 'party');
        $appointments = Appointment::query()->where('company_id', $companyId)->where('client_id', $party->id)->orderByDesc('starts_at')->orderBy('id')->limit(100)->get();

        return response()->json(['data' => $this->views->appointments($appointments, $request, true)]);
    }
}
