<?php

namespace Modules\HR\Leave;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\HR\Models\LeaveType;
use Modules\HR\Support\Errors;
use Modules\HR\Support\HrPack;

/**
 * HR-12: leave types. Statutory ones are copied from the country pack
 * (missing ones only) with their days "needed" until a person confirms
 * them; days are never guessed. Confirming means entering the days: the
 * company then owns the type.
 */
class LeaveTypes
{
    public const FIELDS = ['code', 'name', 'paid', 'accrual', 'entitlement_days', 'count_basis', 'carry_over_max_days', 'allow_negative', 'requires_document', 'accrues_from'];

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
            $existing = LeaveType::query()->where('company_id', $company->id)->whereNotNull('statutory_key')->pluck('statutory_key')->all();
            $codes = LeaveType::query()->where('company_id', $company->id)->whereNull('archived_at')->pluck('code')->map(fn ($c) => strtolower($c))->all();
            $created = [];

            foreach ($types as $type) {
                if (in_array($type['key'], $existing, true)) {
                    continue;
                }

                $code = strtoupper($type['key']);

                while (in_array(strtolower($code), $codes, true)) {
                    $code .= '_P';
                }

                $codes[] = strtolower($code);
                $days = $type['days'] ?? null;
                $confirmed = $days !== null && ! ($type['needs_confirmation'] ?? false);

                $created[] = LeaveType::create([
                    'company_id' => $company->id,
                    'code' => $code,
                    // Names are the tenant's from now on, written once in its language.
                    'name' => __("hr.pack.leave_types.{$type['key']}", [], $locale),
                    'statutory_key' => $type['key'],
                    'paid' => $type['paid'] ?? true,
                    'accrual' => $type['accrual'] ?? 'none',
                    'entitlement_days' => $confirmed ? (string) $days : null,
                    'needs_confirmation' => ! $confirmed,
                    'count_basis' => $type['count_basis'] ?? 'working_days',
                    'carry_over_max_days' => null,
                    'allow_negative' => false,
                ]);
            }

            return $created;
        });
    }

    /** @param array<string, mixed> $data validated */
    public function create(array $data): LeaveType
    {
        $this->assertCodeFree($data['company_id'], $data['code'], null);
        $fields = array_intersect_key($data, array_flip(['company_id', ...self::FIELDS]));
        $fields['needs_confirmation'] = false;
        $fields['accrues_from'] ??= CarbonImmutable::now()->toDateString();

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

            if ($type->needs_confirmation && ($data['confirm'] ?? false) === true) {
                if ($type->entitlement_days === null && $type->accrual !== 'none') {
                    throw Errors::make(422, 'entitlement_required', field: 'entitlement_days');
                }

                $type->needs_confirmation = false;
                $type->accrues_from ??= CarbonImmutable::now()->toDateString();
            }

            if (! $type->needs_confirmation && $type->accrual !== 'none' && $type->entitlement_days === null) {
                throw Errors::make(422, 'entitlement_required', field: 'entitlement_days');
            }

            $type->save();

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
