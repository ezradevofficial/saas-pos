<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\Salon\Http\Requests\ArchiveRequest;
use Modules\Salon\Http\Requests\BranchRequest;
use Modules\Salon\Http\Requests\StoreResourceRequest;
use Modules\Salon\Http\Requests\UpdateOnlineRequest;
use Modules\Salon\Http\Requests\UpdateResourceRequest;
use Modules\Salon\Http\Requests\ViewRequest;
use Modules\Salon\Http\Resources\Views;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\SalonBranch;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Places;

/** SAL-05, SAL-15, SAL-19: chairs and rooms, and a branch's salon floor and public booking page. */
class ResourceController
{
    public function __construct(private readonly Places $places, private readonly Views $views) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Resource::query()->orderBy('name')->orderBy('id');
        $this->places->filterBranches($query, $request->user(), $request->permission());
        $request->filled('company_id') && $query->where('company_id', $request->input('company_id'));
        $request->filled('branch_id') && $query->where('branch_id', $request->input('branch_id'));
        $request->boolean('archived') || $query->whereNull('archived_at');

        return response()->json(['data' => $this->views->resources($query->get())]);
    }

    public function store(StoreResourceRequest $request): JsonResponse
    {
        $branch = $this->places->openBranch((string) $request->input('branch_id'));
        $resource = Errors::named(fn () => Resource::create([...$request->validated(), 'company_id' => $branch->company_id]));

        return response()->json(['data' => $this->views->resources(collect([$resource->refresh()]))[0]], 201);
    }

    public function update(UpdateResourceRequest $request, Resource $salonResource): JsonResponse
    {
        Errors::named(fn () => $salonResource->fill($request->validated())->save());

        return response()->json(['data' => $this->views->resources(collect([$salonResource->refresh()]))[0]]);
    }

    public function archive(ArchiveRequest $request, Resource $salonResource): JsonResponse
    {
        $salonResource->isArchived() || $salonResource->archive();

        return response()->json(['data' => $this->views->resources(collect([$salonResource->refresh()]))[0]]);
    }

    public function online(BranchRequest $request, Branch $branch): JsonResponse
    {
        return response()->json(['data' => $this->views->online($this->page($branch))]);
    }

    public function updateOnline(UpdateOnlineRequest $request, Branch $branch): JsonResponse
    {
        $data = $request->validated();
        $page = $this->page($branch);

        if (! empty($data['location_id'])) {
            $this->places->openLocation($data['location_id'], $branch->id);
        }

        if (array_key_exists('enabled', $data)) {
            $data['online_enabled'] = $data['enabled'];
            unset($data['enabled']);
        }

        $page->fill($data)->save();

        return response()->json(['data' => $this->views->online($page->refresh())]);
    }

    public function regenerateCode(BranchRequest $request, Branch $branch): JsonResponse
    {
        $page = $this->page($branch);
        $this->coded(fn (string $code) => $page->forceFill(['public_code' => $code])->save());

        return response()->json(['data' => $this->views->online($page->refresh())]);
    }

    /** The branch's salon row, made on first use with a random public code. */
    private function page(Branch $branch): SalonBranch
    {
        return SalonBranch::query()->where('branch_id', $branch->id)->first() ?? $this->coded(fn (string $code) => SalonBranch::create([
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'public_code' => $code,
            'location_id' => Location::query()->where('branch_id', $branch->id)->whereNull('archived_at')->orderBy('created_at')->value('id'),
        ]));
    }

    /**
     * Runs $fn with a code nobody can guess: 12 random characters. Codes are unique across tenants (the lookup is by
     * code alone, so another tenant's row cannot be read to check): the database refuses a clash and a new code is tried.
     */
    private function coded(callable $fn): mixed
    {
        for ($try = 0; ; $try++) {
            try {
                return Errors::transaction(fn () => $fn(strtolower(Str::random(12))));
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 3) {
                    throw $e;
                }
            }
        }
    }
}
