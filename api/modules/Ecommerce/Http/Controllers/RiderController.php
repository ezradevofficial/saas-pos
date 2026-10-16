<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Requests\RiderRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\Access;
use Modules\Ecommerce\Support\EcomError;
use Modules\HR\Contracts\EmployeeDirectory;

/**
 * ECOM-26: riders of a company: active users of the tenant (they sign in
 * to the mobile app as themselves), optionally linked to an HR employee of
 * that company while HR is active (HR's read contract). Listed with
 * `ecommerce.delivery.view` or `ecommerce.rider.manage` at the company;
 * managed with `ecommerce.rider.manage`. Archived, never deleted.
 */
class RiderController
{
    public function __construct(private readonly Access $access, private readonly ModuleRegistry $modules) {}

    public function index(ListRequest $request): JsonResponse
    {
        $user = $request->user();
        $query = Rider::query()->where(fn ($q) => $q
            ->whereIn('id', $this->access->byCompany(Rider::query(), $user, 'ecommerce.delivery.view')->select('id'))
            ->orWhereIn('id', $this->access->byCompany(Rider::query(), $user, 'ecommerce.rider.manage')->select('id')))
            ->when($request->validated('status', 'active') !== 'all', fn ($q) => $q->whereNull('archived_at'))
            ->when($request->filled('company'), fn ($q) => $q->where('company_id', $request->validated('company')))
            ->orderBy('name');

        if (! $this->access->can($user, 'ecommerce.delivery.view') && ! $this->access->can($user, 'ecommerce.rider.manage')) {
            abort(403);
        }

        return response()->json(['data' => $query->limit(500)->get()->map(fn (Rider $r) => Present::rider($r))->values()]);
    }

    public function store(RiderRequest $request): JsonResponse
    {
        $this->access->assertInCompany($request->user(), 'ecommerce.rider.manage', (string) $request->validated('company_id'));
        $user = User::query()->whereKey($request->validated('user_id'))->where('status', User::STATUS_ACTIVE)->first();

        if ($user === null) {
            throw EcomError::make('rider_user_invalid', field: 'user_id');
        }

        if (Rider::query()->where('company_id', $request->validated('company_id'))->where('user_id', $user->id)->exists()) {
            throw EcomError::make('rider_exists', field: 'user_id');
        }

        $employee = $this->employee($request->validated('employee_id'), (string) $request->validated('company_id'));
        $rider = Rider::create([
            'company_id' => $request->validated('company_id'),
            'user_id' => $user->id,
            'employee_id' => $employee,
            'name' => mb_substr((string) ($request->validated('name') ?? $user->name), 0, 160),
            'phone' => $request->validated('phone') ?? $user->phone,
            'vehicle' => $request->validated('vehicle'),
            'active' => (bool) $request->validated('active', true),
        ]);

        return response()->json(['data' => Present::rider($rider->refresh())], 201);
    }

    public function update(RiderRequest $request, Rider $ecomRider): JsonResponse
    {
        $this->manage($request, $ecomRider);
        $values = $request->safe()->only(['name', 'phone', 'vehicle', 'active']);

        if ($request->has('employee_id')) {
            $values['employee_id'] = $this->employee($request->validated('employee_id'), $ecomRider->company_id);
        }

        $ecomRider->fill($values)->save();

        return response()->json(['data' => Present::rider($ecomRider->refresh())]);
    }

    public function archive(ListRequest $request, Rider $ecomRider): JsonResponse
    {
        $this->manage($request, $ecomRider);
        $ecomRider->forceFill(['active' => false])->archive();

        return response()->json(['data' => Present::rider($ecomRider->refresh())]);
    }

    private function manage($request, Rider $rider): void
    {
        abort_unless($this->access->inCompany($request->user(), 'ecommerce.rider.manage', $rider->company_id)
            || $this->access->inCompany($request->user(), 'ecommerce.delivery.view', $rider->company_id), 404);
        $this->access->assertInCompany($request->user(), 'ecommerce.rider.manage', $rider->company_id);
    }

    /** The HR employee of the company (HR active), or a refusal; null clears the link. */
    private function employee(?string $id, string $companyId): ?string
    {
        if ($id === null) {
            return null;
        }

        $found = $this->modules->isActive('hr') && class_exists(EmployeeDirectory::class) ? app(EmployeeDirectory::class)->find($id) : null;

        if ($found === null || $found['company_id'] !== $companyId) {
            throw EcomError::make('employee_invalid', field: 'employee_id');
        }

        return $found['id'];
    }
}
