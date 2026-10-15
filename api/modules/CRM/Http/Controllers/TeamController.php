<?php

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\Setup\SetupListRequest;
use Modules\CRM\Http\Requests\Setup\TeamRequest;
use Modules\CRM\Http\Resources\SetupResource;
use Modules\CRM\Models\Team;
use Modules\CRM\Setup\Configuration;

/** CRM-04: sales teams. */
class TeamController
{
    private const RELATIONS = ['company', 'branch', 'leader', 'members.user'];

    public function __construct(private readonly Configuration $configuration) {}

    public function index(SetupListRequest $request): JsonResponse
    {
        $teams = $request->applyFilters(Team::query())->with(self::RELATIONS)->orderBy('name')->get();

        return response()->json(['data' => $teams->map(fn (Team $team) => SetupResource::team($team))->values()]);
    }

    public function store(TeamRequest $request): JsonResponse
    {
        return $this->present($this->configuration->saveTeam(null, $request->company(), $request->validated()), 201);
    }

    public function update(TeamRequest $request, Team $crmTeam): JsonResponse
    {
        return $this->present($this->configuration->saveTeam($crmTeam, $request->company(), $request->validated()));
    }

    public function archive(TeamRequest $request, Team $crmTeam): JsonResponse
    {
        $crmTeam->archive();

        return $this->present($crmTeam);
    }

    public function restore(TeamRequest $request, Team $crmTeam): JsonResponse
    {
        $crmTeam->restore();

        return $this->present($crmTeam);
    }

    private function present(Team $team, int $status = 200): JsonResponse
    {
        return response()->json(['data' => SetupResource::team($team->refresh()->load(self::RELATIONS))], $status);
    }
}
