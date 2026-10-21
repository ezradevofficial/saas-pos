<?php

namespace Modules\HR\Leave;

use App\Core\CountryPacks\ResearchStatus;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Support\Errors;
use Modules\HR\Support\HrPack;

/**
 * HR-12: leave types. Statutory ones are copied from the country pack
 * (missing ones only) with their days when the pack has usable ones
 * (confirmed, or researched at high or medium confidence: the type then
 * keeps the research, CP-01), else "needed" until a person confirms them;
 * days are never guessed. A type still "needed" takes the pack's days
 * when they become usable. Confirming or changing the days makes the
 * company own the type (its research is cleared).
 */
class LeaveTypes
{
    public const FIELDS = ['code', 'name', 'paid', 'accrual', 'entitlement_days', 'count_basis', 'carry_over_max_days', 'allow_negative', 'requires_document', 'accrues_from', 'qualifying_months', 'half_pay_days', 'events'];

    /** CP-01: what re-applying the pack fills in on a type still "Days needed" (with `accrual`). */
    private const DAYS_FIELDS = ['entitlement_days', 'needs_confirmation', 'qualifying_months', 'half_pay_days', 'events', 'research', 'accrues_from'];

    /** Fields that come from the pack: changing one makes the days the company's own (no longer researched, CP-01). */
    private const PACK_FIELDS = ['paid', 'entitlement_days', 'count_basis', 'qualifying_months', 'half_pay_days', 'events'];

    public function __construct(
        private readonly HrPack $pack,
        private readonly TenantContext $tenants,
    ) {}

    /** @return list<LeaveType> the types copied now */
    public function applyPack(Company $company): array
    {
        $types = $this->pack->statutoryLeaveTypes($company->country, CarbonImmutable::now());

        if ($types === []) {
            return [];
        }

        $locale = Tenant::query()->whereKey($this->tenants->require())->value('default_locale') ?: 'en';

        return Errors::transaction(function () use ($company, $types, $locale) {
            $existing = LeaveType::query()->where('company_id', $company->id)->whereNotNull('statutory_key')->get()->keyBy('statutory_key');
            $codes = LeaveType::query()->where('company_id', $company->id)->whereNull('archived_at')->pluck('code')->map(fn ($c) => strtolower($c))->all();
            $created = [];

            foreach ($types as $type) {
                if ($existing->has($type['key'])) {
                    // CP-01: a type still "Days needed" (nothing can have used it) takes the pack's usable days now.
                    $current = $existing[$type['key']];

                    // Only the days and how they accrue are taken (the accrual too: an older copy may say `none`); fields
                    // the company may have edited (name, count basis, carry-over, documents) are left as they are. A pay
                    // the pack leaves unconfirmed (null) is taken too: nothing has used the type, and it blocks runs.
                    if ($current->needs_confirmation && $current->entitlement_days === null && self::usable($type)) {
                        $days = array_intersect_key(self::fromPack($type, $company->id), array_flip(self::DAYS_FIELDS));
                        $days['accrual'] = $type['accrual'] ?? $current->accrual;

                        if (array_key_exists('paid', $type) && $type['paid'] === null) {
                            $days['paid'] = null;
                        }

                        $current->fill($days)->save();
                        $created[] = $current->refresh();
                    }

                    continue;
                }

                $code = strtoupper($type['key']);

                while (in_array(strtolower($code), $codes, true)) {
                    $code .= '_P';
                }

                $codes[] = strtolower($code);

                $created[] = LeaveType::create([
                    'company_id' => $company->id,
                    'code' => $code,
                    // Names are the tenant's from now on, written once in its language.
                    'name' => __("hr.pack.leave_types.{$type['key']}", [], $locale),
                    'statutory_key' => $type['key'],
                    'accrual' => $type['accrual'] ?? 'none',
                    'carry_over_max_days' => null,
                    'allow_negative' => false,
                    ...self::fromPack($type, $company->id),
                ]);
            }

            return $created;
        });
    }

    /** A pack leave type's days are usable: given, and confirmed or researched at high or medium confidence (CP-01). */
    private static function usable(array $type): bool
    {
        return ($type['days'] ?? null) !== null && ResearchStatus::usable($type);
    }

    /**
     * The values a company's type takes from a pack leave type: its days,
     * pay, qualifying service, half-pay days and events when usable (else
     * "Days needed"), and the research they come from.
     *
     * @return array<string, mixed>
     */
    private static function fromPack(array $type, string $companyId): array
    {
        $usable = self::usable($type);

        return [
            // CP-01: null = the pay rate is not confirmed (CD maternity); runs with such days are blocked in Payroll.
            'paid' => array_key_exists('paid', $type) ? $type['paid'] : true,
            'entitlement_days' => $usable ? (string) $type['days'] : null,
            'needs_confirmation' => ! $usable,
            'count_basis' => $type['count_basis'] ?? 'working_days',
            'qualifying_months' => $usable ? ($type['qualifying_months'] ?? null) : null,
            'half_pay_days' => $usable && isset($type['half_pay_days']) ? (string) $type['half_pay_days'] : null,
            'events' => $usable ? ($type['events'] ?? null) : null,
            'research' => $usable ? ResearchStatus::research($type) : null,
            'accrues_from' => $usable ? CompanyClock::today($companyId) : null,
        ];
    }

    /** @param array<string, mixed> $data validated */
    public function create(array $data): LeaveType
    {
        $this->assertCodeFree($data['company_id'], $data['code'], null);
        $fields = array_intersect_key($data, array_flip(['company_id', ...self::FIELDS]));
        $fields['needs_confirmation'] = false;
        $fields['accrues_from'] ??= CompanyClock::today($data['company_id']);

        if (($fields['accrual'] ?? 'none') !== 'none' && ($fields['entitlement_days'] ?? null) === null) {
            throw Errors::make(422, 'entitlement_required', field: 'entitlement_days');
        }

        return Errors::transaction(fn () => LeaveType::create($fields));
    }

    /**
     * Change a type. Entering the days of a type that needs confirmation
     * confirms it (HR-12).
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function update(LeaveType $type, array $data): LeaveType
    {
        if (isset($data['code'])) {
            $this->assertCodeFree($type->company_id, $data['code'], $type->id);
        }

        return Errors::transaction(function () use ($type, $data) {
            $type = LeaveType::query()->whereKey($type->id)->lockForUpdate()->firstOrFail();
            $type->fill(array_intersect_key($data, array_flip(self::FIELDS)));

            // CP-01: once the company changes or confirms the pack's values, they are its own.
            if ($type->research !== null && ($type->isDirty(self::PACK_FIELDS) || ($data['confirm'] ?? false) === true)) {
                $type->research = null;
            }

            if ($type->needs_confirmation && ($data['confirm'] ?? false) === true) {
                if ($type->entitlement_days === null && $type->accrual !== 'none') {
                    throw Errors::make(422, 'entitlement_required', field: 'entitlement_days');
                }

                $type->needs_confirmation = false;
                $type->accrues_from ??= CompanyClock::today($type->company_id);
            }

            if (! $type->needs_confirmation && $type->accrual !== 'none' && $type->entitlement_days === null) {
                throw Errors::make(422, 'entitlement_required', field: 'entitlement_days');
            }

            $paidChanged = $type->isDirty('paid');
            $type->save();

            // CP-01: Payroll's copy of this type's leave follows its pay (unconfirmed, paid or unpaid) on the next sync.
            if ($paidChanged) {
                LeaveRequest::query()->where('leave_type_id', $type->id)->update(['updated_at' => now()]);
            }

            return $type->refresh();
        });
    }

    private function assertCodeFree(string $companyId, string $code, ?string $exceptId): void
    {
        $taken = LeaveType::query()->where('company_id', $companyId)->whereNull('archived_at')->whereRaw('lower(code) = lower(?)', [$code])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();

        if ($taken) {
            throw Errors::make(422, 'code_taken', field: 'code');
        }
    }
}
