<?php

namespace Modules\CRM\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use App\Core\Tenancy\Models\Company;
use Modules\CRM\CrmServiceProvider;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\Stage;
use Modules\CRM\Support\CrmAccess;

/**
 * LAY-01, CRM-31: the open pipeline the reader sees (CRM-08) in one
 * currency (`currency`, else the base currency of the first company they
 * reach): a KPI of its value (with the weighted value and count), or a
 * chart of value by stage of one pipeline (`pipeline`, else that
 * company's default). Opportunities in other currencies are not
 * converted (no rate is guessed for an open deal).
 */
class PipelineValue extends DashboardSource
{
    public function key(): string
    {
        return 'crm.pipeline_value';
    }

    public function module(): string
    {
        return CrmServiceProvider::MODULE;
    }

    public function widgets(): array
    {
        return [self::KPI, self::CHART];
    }

    public function permissions(): array
    {
        return ['crm.opportunity.view'];
    }

    public function rules(): array
    {
        return [
            'currency' => ['sometimes', 'string', 'size:3'],
            'pipeline' => ['sometimes', 'uuid'],
            'widget' => ['sometimes', 'string', 'in:kpi,chart'],
        ];
    }

    public function data(User $user, array $params): array
    {
        $access = app(CrmAccess::class);
        $companies = $access->companies($user, 'crm.opportunity.view');
        $company = Company::query()->when($companies !== null, fn ($q) => $q->whereIn('id', $companies))->orderBy('created_at')->first();
        $currency = strtoupper((string) ($params['currency'] ?? $company?->base_currency ?? ''));
        $open = fn () => $access->visible(Opportunity::query(), $user, 'opportunity')->where('status', Opportunity::OPEN)->whereNull('archived_at')->where('currency', $currency);

        if (($params['widget'] ?? 'kpi') === 'chart') {
            $pipeline = isset($params['pipeline'])
                ? Pipeline::query()->find($params['pipeline'])
                : Pipeline::query()->where('company_id', $company?->id)->where('is_default', true)->whereNull('archived_at')->first();
            $values = $pipeline === null ? collect() : $open()->where('pipeline_id', $pipeline->id)->groupBy('stage_id')->selectRaw('stage_id, sum(value_minor) as amount')->pluck('amount', 'stage_id');

            return [
                'kind' => 'money',
                'currency' => $currency,
                'points' => $pipeline === null ? [] : Stage::query()->where('pipeline_id', $pipeline->id)->whereNull('archived_at')->orderBy('position')->get()
                    ->map(fn (Stage $stage) => ['label' => $stage->name, 'value' => (string) ($values[$stage->id] ?? '0')])->values()->all(),
            ];
        }

        $row = $open()->selectRaw('count(*) as count, coalesce(sum(value_minor), 0) as amount, coalesce(sum(round(value_minor::numeric * probability / 100)), 0) as weighted')->first();

        return [
            'kind' => 'money',
            'value' => (string) ($row?->amount ?? '0'),
            'weighted' => (string) ($row?->weighted ?? '0'),
            'currency' => $currency,
            'count' => (int) ($row?->count ?? 0),
            'complete' => true,
            'to' => '/crm/opportunities',
        ];
    }
}
