<?php

namespace Modules\CRM\Http\Controllers;

use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\CRM\Http\Requests\ReadRequest;
use Modules\CRM\Http\Requests\TargetRequest;
use Modules\CRM\Http\Resources\TargetResource;
use Modules\CRM\Models\Target;
use Modules\CRM\Targets\Targets;

/** CRM-30: sales targets with their actuals. */
class TargetController
{
    public function __construct(private readonly Targets $targets) {}

    /** CRM-30: the targets the reader sees, filtered in SQL and paged; subjects and teams read at once. */
    public function index(ReadRequest $request): AnonymousResourceCollection
    {
        $page = $this->targets->visibleQuery($request->user(), $request->validated('company'), $request->validated('status') === 'all')
            ->paginate((int) $request->validated('per_page', ListRequest::PER_PAGE))->withQueryString();
        TargetResource::prepare(collect($page->items()));

        try {
            return TargetResource::collection($page);
        } finally {
            TargetResource::$batch = null;
        }
    }

    public function store(TargetRequest $request): JsonResponse
    {
        return $this->present($request, $this->targets->save(null, $request->validated(), $request->user()), 201);
    }

    public function update(TargetRequest $request, Target $crmTarget): JsonResponse
    {
        return $this->present($request, $this->targets->save($crmTarget, $request->validated(), $request->user()));
    }

    public function archive(TargetRequest $request, Target $crmTarget): JsonResponse
    {
        $crmTarget->archive();

        return $this->present($request, $crmTarget);
    }

    private function present(TargetRequest $request, Target $target, int $status = 200): JsonResponse
    {
        return response()->json(['data' => TargetResource::make($target->refresh()->load(['company', 'branch']))->resolve($request)], $status);
    }
}
