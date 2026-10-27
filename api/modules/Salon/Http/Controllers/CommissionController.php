<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Salon\Commissions\Batches;
use Modules\Salon\Http\Requests\ActionRequest;
use Modules\Salon\Http\Requests\ArchiveRequest;
use Modules\Salon\Http\Requests\OwnCommissionsRequest;
use Modules\Salon\Http\Requests\ReasonRequest;
use Modules\Salon\Http\Requests\StoreBatchRequest;
use Modules\Salon\Http\Requests\StoreRuleRequest;
use Modules\Salon\Http\Requests\UpdateRuleRequest;
use Modules\Salon\Http\Requests\ViewRequest;
use Modules\Salon\Http\Resources\Views;
use Modules\Salon\Models\CommissionBatch;
use Modules\Salon\Models\CommissionEntry;
use Modules\Salon\Models\CommissionRule;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;
use Modules\Salon\Models\Tip;
use Modules\Salon\Support\Access;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Places;

/** SAL-20 to SAL-24: commission rules, the ledger, batches and their approval and export. */
class CommissionController
{
    use Paginates;

    public function __construct(
        private readonly Batches $batches,
        private readonly Views $views,
        private readonly Places $places,
        private readonly Access $access,
        private readonly ScopeResolver $resolver,
    ) {}

    public function rules(ViewRequest $request): JsonResponse
    {
        $query = CommissionRule::query()->orderByDesc('effective_from')->orderBy('id');
        $this->places->filterCompanies($query, $request->user(), $request->permission());
        $request->filled('company_id') && $query->where('company_id', $request->input('company_id'));
        $request->boolean('archived') || $query->whereNull('archived_at');

        return response()->json(['data' => $this->views->rules($query->get())]);
    }

    public function storeRule(StoreRuleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->assertRule($data['company_id'], $data);
        $rule = CommissionRule::create($data);

        return response()->json(['data' => $this->views->rules(collect([$rule->refresh()]))[0]], 201);
    }

    public function updateRule(UpdateRuleRequest $request, CommissionRule $salonRule): JsonResponse
    {
        $data = [...$salonRule->only(['staff_id', 'service_id', 'applies_to', 'kind', 'rate', 'amount_minor', 'currency']), 'effective_from' => $salonRule->effective_from->toDateString(), ...$request->validated()];
        $this->assertRule($salonRule->company_id, $data);
        $salonRule->fill($request->validated())->save();

        return response()->json(['data' => $this->views->rules(collect([$salonRule->refresh()]))[0]]);
    }

    public function archiveRule(ArchiveRequest $request, CommissionRule $salonRule): JsonResponse
    {
        $salonRule->isArchived() || $salonRule->archive();

        return response()->json(['data' => $this->views->rules(collect([$salonRule->refresh()]))[0]]);
    }

    /** The commission ledger: everything with `salon.commission.view`, one's own with `view_own`. */
    public function entries(OwnCommissionsRequest $request): JsonResponse
    {
        $query = CommissionEntry::query()->orderByDesc('earned_at')->orderBy('id');
        $this->scopeToReader($query, $request);

        foreach (['company_id', 'staff_id', 'batch_id'] as $filter) {
            $request->filled($filter) && $query->where($filter, $request->input($filter));
        }

        $request->boolean('unbatched') && $query->whereNull('batch_id');
        $request->filled('from') && $query->where('earned_at', '>=', CarbonImmutable::parse($request->input('from').' 00:00:00')->utc());
        $request->filled('to') && $query->where('earned_at', '<', CarbonImmutable::parse($request->input('to').' 00:00:00')->addDay()->utc());

        return $this->paged($query, $request, fn ($rows) => $this->views->entries($rows));
    }

    public function tips(OwnCommissionsRequest $request): JsonResponse
    {
        $query = Tip::query()->orderByDesc('earned_at')->orderBy('id');
        $this->scopeToReader($query, $request);
        $request->filled('company_id') && $query->where('company_id', $request->input('company_id'));
        $request->filled('staff_id') && $query->where('staff_id', $request->input('staff_id'));
        $request->filled('from') && $query->where('earned_at', '>=', CarbonImmutable::parse($request->input('from').' 00:00:00')->utc());
        $request->filled('to') && $query->where('earned_at', '<', CarbonImmutable::parse($request->input('to').' 00:00:00')->addDay()->utc());
        $names = Staff::query()->pluck('name', 'id');

        return $this->paged($query, $request, fn ($rows) => $rows->map(fn (Tip $t) => [
            'id' => $t->id, 'staff_id' => $t->staff_id, 'staff_name' => $names[$t->staff_id] ?? '', 'kind' => $t->kind, 'sale_id' => $t->sale_id, 'currency' => $t->currency,
            'amount_minor' => (string) $t->amount_minor, 'earned_at' => $t->earned_at->toIso8601String(),
        ])->values()->all());
    }

    public function batchIndex(ViewRequest $request): JsonResponse
    {
        $query = CommissionBatch::query()->orderByDesc('created_at')->orderBy('id');
        $this->places->filterScoped($query, $request->user(), $request->permission(), 'company_id', 'branch_id');
        $request->filled('company_id') && $query->where('company_id', $request->input('company_id'));
        $request->filled('status') && $query->where('status', $request->input('status'));

        return $this->paged($query, $request, fn ($rows) => $rows->map(fn (CommissionBatch $b) => $this->views->batch($b))->values()->all());
    }

    public function batchShow(ActionRequest $request, CommissionBatch $salonBatch): JsonResponse
    {
        return response()->json(['data' => $this->views->batch($salonBatch, true)]);
    }

    public function batchStore(StoreBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (! empty($data['branch_id']) && $this->places->openBranch($data['branch_id'])->company_id !== $data['company_id']) {
            throw Errors::make(422, 'branch_unknown', field: 'branch_id');
        }

        $batch = $this->batches->create($data['company_id'], $data['branch_id'] ?? null, $data['period_start'], $data['period_end'], $data['currency'], $request->user());

        return response()->json(['data' => $this->views->batch($batch, true)], 201);
    }

    public function submit(ActionRequest $request, CommissionBatch $salonBatch): JsonResponse
    {
        return response()->json(['data' => $this->views->batch($this->batches->submit($salonBatch, $request->user()), true)]);
    }

    public function approve(ActionRequest $request, CommissionBatch $salonBatch): JsonResponse
    {
        return response()->json(['data' => $this->views->batch($this->batches->approve($salonBatch, $request->user()), true)]);
    }

    public function reject(ReasonRequest $request, CommissionBatch $salonBatch): JsonResponse
    {
        return response()->json(['data' => $this->views->batch($this->batches->reject($salonBatch, (string) $request->input('reason'), $request->user()), true)]);
    }

    public function export(ActionRequest $request, CommissionBatch $salonBatch): JsonResponse
    {
        $result = $this->batches->export($salonBatch, $request->user());

        return response()->json(['data' => $this->views->batch($result['batch'], true), 'refused' => $result['refused']]);
    }

    public function csv(ActionRequest $request, CommissionBatch $salonBatch): Response
    {
        return response($this->batches->csv($salonBatch), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$salonBatch->number.'.csv"']);
    }

    /** Everything at the companies where the reader holds `commission.view`; else only their own staff record's. */
    private function scopeToReader($query, OwnCommissionsRequest $request): void
    {
        $user = $request->user();
        $own = Staff::query()->where('user_id', $user->id)->whereNull('archived_at')->pluck('id')->all();
        $all = $this->resolver->visibleIds($user, 'salon.commission.view');

        if ($all->all) {
            return;
        }

        $query->where(fn ($w) => $w->where(fn ($a) => $a->whereIn('company_id', $all->idsFor(Scope::COMPANY))->orWhereIn('branch_id', $all->idsFor(Scope::BRANCH)))->orWhereIn('staff_id', $own));
    }

    /** SAL-20: a rule names things of its company, and a rule for products has no service. */
    private function assertRule(string $companyId, array $data): void
    {
        if (! empty($data['staff_id']) && ! Staff::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey($data['staff_id'])->exists()) {
            throw Errors::make(422, 'staff_unknown', field: 'staff_id');
        }

        if (! empty($data['service_id']) && ! Service::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey($data['service_id'])->exists()) {
            throw Errors::make(422, 'service_unknown', field: 'service_id');
        }

        if (($data['applies_to'] ?? 'service') === 'product' && ! empty($data['service_id'])) {
            throw Errors::make(422, 'rule_product_service', field: 'service_id');
        }
    }
}
