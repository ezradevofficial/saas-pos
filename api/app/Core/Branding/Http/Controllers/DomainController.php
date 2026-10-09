<?php

namespace App\Core\Branding\Http\Controllers;

use App\Core\Branding\Domains\TenantDomains;
use App\Core\Branding\Http\Requests\DomainRequest;
use App\Core\Branding\Http\Requests\StoreDomainRequest;
use App\Core\Branding\Models\TenantDomain;
use Illuminate\Http\JsonResponse;

/**
 * BR-05: the tenant's custom domains (`core.domain.manage`, tenant scope):
 * list (archived ones left out), add (answers the TXT record to create),
 * check now, and archive. Every change is audited as `core.domain.*`.
 */
class DomainController
{
    public function __construct(private readonly TenantDomains $domains) {}

    public function index(DomainRequest $request): JsonResponse
    {
        $domains = TenantDomain::query()->whereNull('archived_at')->orderBy('host')->get();

        return response()->json([
            'data' => $domains->map(fn (TenantDomain $d) => $this->present($d))->values(),
            'meta' => ['cname_target' => config('branding.domains.cname_target')],
        ]);
    }

    public function store(StoreDomainRequest $request): JsonResponse
    {
        $domain = $this->domains->add($request->validated('host'), $request->user());

        return response()->json(['data' => $this->present($domain)], 201);
    }

    public function check(DomainRequest $request): JsonResponse
    {
        $domain = $request->domain();
        abort_if($domain->archived_at !== null, 404);

        return response()->json(['data' => $this->present($this->domains->retry($domain))]);
    }

    public function archive(DomainRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->domains->archive($request->domain()))]);
    }

    /** @return array<string, mixed> */
    private function present(TenantDomain $domain): array
    {
        return [
            'id' => $domain->id,
            'host' => $domain->host,
            'status' => $domain->status,
            'record' => [
                'type' => 'TXT',
                'name' => TenantDomains::recordName($domain),
                'value' => TenantDomains::recordValue($domain),
            ],
            'failure' => $domain->failure,
            'checked_at' => $domain->checked_at?->toIso8601String(),
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'archived_at' => $domain->archived_at?->toIso8601String(),
            'created_at' => $domain->created_at?->toIso8601String(),
        ];
    }
}
