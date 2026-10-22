<?php

namespace Modules\Payroll\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\CountryPacks\ResearchStatus;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Payroll\Http\Requests\ItemRequest;
use Modules\Payroll\Http\Requests\NotApplicableRequest;
use Modules\Payroll\Http\Requests\PayGroupRequest;
use Modules\Payroll\Http\Requests\SettingsRequest;
use Modules\Payroll\Http\Requests\SetupListRequest;
use Modules\Payroll\Http\Requests\StoreSetupRequest;
use Modules\Payroll\Http\Resources\SetupResource;
use Modules\Payroll\Models\Item;
use Modules\Payroll\Models\PayGroup;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\Settings;
use Modules\Payroll\Statutory\MissingValues;
use Modules\Payroll\Statutory\NotApplicableMarks;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Support\Errors;

/** PAY-02 to PAY-04, PAY-08: company settings, pay groups, pay items and the statutory pack in force. */
class SetupController
{
    public function settings(SettingsRequest $request, Company $company): SetupResource
    {
        return SetupResource::make(Settings::query()->where('company_id', $company->id)->first()
            ?? new Settings(['company_id' => $company->id, 'premiums_taxable' => true, 'premiums_contributory' => true]));
    }

    public function updateSettings(SettingsRequest $request, Company $company): JsonResponse
    {
        $settings = Settings::query()->firstOrNew(['company_id' => $company->id], ['premiums_taxable' => true, 'premiums_contributory' => true]);
        $settings->fill($request->validated())->save();

        return SetupResource::make($settings->refresh())->response()->setStatusCode(200);
    }

    /** PAY-08: the company's pack in force today: its components with labels, what is missing, and the pack's own list of values to confirm. */
    public function statutory(SettingsRequest $request, Company $company, PayrollPack $packs, NotApplicableMarks $marks): JsonResponse
    {
        $pack = $packs->of($company->country);

        if ($pack === null) {
            return response()->json(['data' => ['country' => $company->country, 'available' => false]]);
        }

        $today = CarbonImmutable::now($company->timezone ?: 'UTC')->startOfDay();
        // CP-01: marks whose period became usable are voided (audited); those of today's periods apply.
        $notApplicable = NotApplicableMarks::inEffect($pack, $marks->refresh($company->id, $pack), $today);

        $used = ['insurance_premiums' => true, 'dependants' => true, 'children' => true, ...array_fill_keys(Item::PRE_TAX_CATEGORIES, true)];
        $missing = MissingValues::check($pack, $today, 'monthly', $used, array_keys($notApplicable));
        $byCode = array_column($missing, 'fields', 'component');
        $inForce = PayrollPack::inForce($pack, $today);
        $notices = PayrollPack::notices($inForce, $used, $notApplicable);

        return response()->json(['data' => [
            'country' => $company->country,
            'available' => true,
            'currency' => $pack['currency'] ?? null,
            'non_monthly' => $pack['non_monthly'] ?? null,
            'components' => array_map(fn (array $c) => [
                'code' => $c['code'], 'name' => PayrollPack::label('components', $c['code']), 'kind' => $c['kind'], 'method' => $c['method'],
                'required' => $c['required'], 'when' => $c['when'], 'in_force' => $c['values'] !== null,
                'effective_from' => $c['values']['effective_from'] ?? null,
                'missing' => array_map(fn (string $f) => ['field' => $f, 'name' => PayrollPack::label('fields', $f)], $byCode[$c['code']] ?? []),
                // CP-01: the value's status, research confidence, date and sources (the statutory rates page lists them).
                'status' => $c['values'] === null ? ResearchStatus::NEEDS_CONFIRMATION : ResearchStatus::of($c['values']),
                'confidence' => $c['values']['confidence'] ?? null,
                'researched_on' => $c['values']['researched_on'] ?? null,
                'source' => array_values((array) ($c['values']['source'] ?? [])),
                'assumed' => array_map(fn (string $f) => ['field' => $f, 'name' => PayrollPack::assumedLabel($f)], array_values((array) ($c['values']['assumed'] ?? []))),
                'values' => self::values($c['values']),
                'may_not_apply' => $c['may_not_apply'],
                'not_applicable' => PayrollPack::notApplicable($c, array_keys($notApplicable)) ? $notApplicable[$c['code']] : null,
                'not_applied' => PayrollPack::notApplied($c),
            ], $inForce),
            'researched' => $notices['researched'] !== [],
            'missing' => $missing === [] ? null : MissingValues::describe($missing),
            'ready' => $missing === [],
            // CP-01: how many components in force are not confirmed yet. The pack's own to-do
            // notes are for the platform team (console, ADM-04) and never sent to tenants.
            'to_confirm' => count(array_filter($inForce, fn (array $c) => ($c['values'] === null ? ResearchStatus::NEEDS_CONFIRMATION : ResearchStatus::of($c['values'])) !== ResearchStatus::CONFIRMED)),
            'returns' => array_map(fn (array $r) => ['key' => $r['key'], 'name' => PayrollPack::label('returns', $r['key']), 'needs_confirmation' => (bool) ($r['needs_confirmation'] ?? true) || ($r['layout'] ?? null) === null], $pack['returns'] ?? []),
        ]]);
    }

    /**
     * CP-01: mark a pack component the company does not owe "not
     * applicable" (only components the pack allows, e.g. CD INPP), with
     * the reason (`payroll.statutory.exempt`); audited as
     * `payroll.statutory.not_applicable`. The mark is made against the
     * component's period in force today and only while that period is
     * unconfirmed: once it becomes usable the mark is voided
     * (NotApplicableMarks). Its missing values then no longer block runs,
     * and runs record it.
     */
    public function markNotApplicable(NotApplicableRequest $request, Company $company, PayrollPack $packs, Auditor $auditor): JsonResponse
    {
        $code = (string) $request->validated('component');
        $component = collect(($packs->of($company->country) ?? [])['components'] ?? [])->firstWhere('code', $code);

        if ($component === null || ! ($component['may_not_apply'] ?? false)) {
            throw Errors::make(422, 'component_always_applies', ['component' => PayrollPack::label('components', $code)], 'component');
        }

        $today = CarbonImmutable::now($company->timezone ?: 'UTC')->startOfDay();
        $period = collect(PayrollPack::inForce($packs->of($company->country), $today))->firstWhere('code', $code)['values'] ?? null;

        // Only an unconfirmed period can be marked: a usable one applies.
        if ($period === null || ResearchStatus::usable($period)) {
            throw Errors::make(422, 'component_confirmed', ['component' => PayrollPack::label('components', $code)], 'component');
        }

        $settings = Errors::transaction(function () use ($company, $code, $request, $auditor, $period) {
            $settings = Settings::query()->where('company_id', $company->id)->lockForUpdate()->first()
                ?? Settings::query()->create(['company_id' => $company->id, 'premiums_taxable' => true, 'premiums_contributory' => true]);
            $before = $settings->not_applicable ?? [];
            $mark = ['reason' => (string) $request->validated('reason'), 'marked_by' => $request->user()?->id, 'marked_at' => CarbonImmutable::now()->toIso8601String(), 'period_from' => $period['effective_from']];
            $settings->not_applicable = [...$before, $code => $mark];
            $settings->save();
            $auditor->record('payroll.statutory.not_applicable', $settings, ['component' => $code, 'not_applicable' => $before[$code] ?? null], ['component' => $code, 'country' => $company->country, ...$mark]);

            return $settings;
        });

        return SetupResource::make($settings->refresh())->response()->setStatusCode(200);
    }

    /** CP-01: undo a "not applicable" mark: the component applies (and blocks runs while unconfirmed) again; audited. */
    public function clearNotApplicable(NotApplicableRequest $request, Company $company, string $component, Auditor $auditor): JsonResponse
    {
        $settings = Errors::transaction(function () use ($company, $component, $auditor) {
            $settings = Settings::query()->where('company_id', $company->id)->lockForUpdate()->first();
            $before = $settings?->not_applicable ?? [];

            if ($settings === null || ! array_key_exists($component, $before)) {
                return $settings;
            }

            $after = $before;
            unset($after[$component]);
            $settings->not_applicable = $after === [] ? null : $after;
            $settings->save();
            $auditor->record('payroll.statutory.applicable', $settings, ['component' => $component, 'not_applicable' => $before[$component]], ['component' => $component, 'country' => $company->country]);

            return $settings;
        });

        return SetupResource::make($settings?->refresh() ?? new Settings(['company_id' => $company->id, 'premiums_taxable' => true, 'premiums_contributory' => true]))->response()->setStatusCode(200);
    }

    /** The figures of a period as the statutory rates page shows them (status fields apart). */
    private static function values(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_diff_key($values, array_flip([...ResearchStatus::FIELDS, 'effective_from', 'effective_to', 'needs_confirmation']));
    }

    public function payGroups(SetupListRequest $request): AnonymousResourceCollection
    {
        $query = $request->applyFilters(PayGroup::query()->with('company'), ['code', 'name'])->orderBy('code')->orderBy('id');

        return SetupResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function storePayGroup(StoreSetupRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($data['frequency'] === PayGroup::MONTHLY) {
            $data['anchor_date'] = null;
        } elseif (isset($data['pay_day'])) {
            $data['pay_day'] = null;
        }

        return SetupResource::make($this->unique(fn () => PayGroup::query()->create($data))->load('company'))->response()->setStatusCode(201);
    }

    public function updatePayGroup(PayGroupRequest $request, PayGroup $payrollPayGroup): SetupResource
    {
        $data = $request->validated();

        if (array_key_exists('anchor_date', $data)) {
            $anchor = $data['anchor_date'];

            // Review fix 3: a weekly or bi-weekly group keeps an anchor; once it has runs its periods never move.
            if ($payrollPayGroup->frequency !== PayGroup::MONTHLY && $anchor === null) {
                throw Errors::make(422, 'anchor_required', field: 'anchor_date');
            }

            if ($anchor !== $payrollPayGroup->anchor_date?->toDateString() && Run::query()->where('pay_group_id', $payrollPayGroup->id)->exists()) {
                throw Errors::make(422, 'pay_group_has_runs', field: 'anchor_date');
            }
        }

        $this->unique(fn () => $payrollPayGroup->fill($data)->save());

        return SetupResource::make($payrollPayGroup->refresh()->load('company'));
    }

    public function archivePayGroup(PayGroupRequest $request, PayGroup $payrollPayGroup): SetupResource
    {
        if (! $payrollPayGroup->isArchived()) {
            $payrollPayGroup->archive();
        }

        return SetupResource::make($payrollPayGroup->refresh()->load('company'));
    }

    public function items(SetupListRequest $request): AnonymousResourceCollection
    {
        $query = $request->applyFilters(Item::query()->with('company'), ['code', 'name'])->orderBy('kind')->orderBy('code')->orderBy('id');

        return SetupResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function storeItem(StoreSetupRequest $request): JsonResponse
    {
        $data = self::flags($request->validated(), $request->validated('kind'));

        return SetupResource::make($this->unique(fn () => Item::query()->create($data))->load('company'))->response()->setStatusCode(201);
    }

    public function updateItem(ItemRequest $request, Item $payrollItem): SetupResource
    {
        $this->unique(fn () => $payrollItem->fill(self::flags($request->validated(), $payrollItem->kind, $payrollItem))->save());

        return SetupResource::make($payrollItem->refresh()->load('company'));
    }

    public function archiveItem(ItemRequest $request, Item $payrollItem): SetupResource
    {
        if (! $payrollItem->isArchived()) {
            $payrollItem->archive();
        }

        return SetupResource::make($payrollItem->refresh()->load('company'));
    }

    /**
     * PAY-04: an earning has no deduction flags and a deduction no earning
     * flags (`item_flag_kind`); a pre-tax category needs a pre-tax
     * deduction (`pre_tax_category_needs_pre_tax`), and clearing pre-tax
     * clears it.
     */
    private static function flags(array $data, string $kind, ?Item $item = null): array
    {
        $other = $kind === Item::EARNING ? ['pre_tax', 'insurance_premium', 'pre_tax_category'] : ['taxable', 'contributory'];

        foreach ($other as $flag) {
            if (($data[$flag] ?? false) === true || ($flag === 'pre_tax_category' && ($data[$flag] ?? null) !== null)) {
                throw Errors::make(422, 'item_flag_kind', ['flag' => $flag], $flag);
            }
        }

        $preTax = $data['pre_tax'] ?? $item?->pre_tax ?? false;

        if (! $preTax) {
            if (($data['pre_tax_category'] ?? null) !== null) {
                throw Errors::make(422, 'pre_tax_category_needs_pre_tax', field: 'pre_tax_category');
            }

            if ($item?->pre_tax_category !== null) {
                $data['pre_tax_category'] = null;
            }

            return $data;
        }

        // S3, PAY-07: where the company's pack limits pre-tax deductions by category (KE), a pre-tax deduction
        // says which limit applies; an uncategorised one would escape the limits.
        $category = array_key_exists('pre_tax_category', $data) ? $data['pre_tax_category'] : $item?->pre_tax_category;
        $companyId = $data['company_id'] ?? $item?->company_id;
        $pack = app(PayrollPack::class)->of(Company::query()->whereKey($companyId)->value('country'));
        $limited = collect($pack['components'] ?? [])->contains(fn (array $c) => ($c['kind'] ?? null) === 'deduction_limit');

        if ($kind === Item::DEDUCTION && $limited && $category === null) {
            throw Errors::make(422, 'pre_tax_category_required', field: 'pre_tax_category');
        }

        return $data;
    }

    /** A code taken by another active row of the company: `code_taken`. */
    private function unique(callable $fn): mixed
    {
        try {
            return Errors::transaction($fn);
        } catch (UniqueConstraintViolationException) {
            throw Errors::make(422, 'code_taken', field: 'code');
        }
    }
}
