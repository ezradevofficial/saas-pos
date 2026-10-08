<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Insights\WorkflowInsights;
use App\Core\Workflow\WorkflowAccess;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WF-10: GET workflow-insights?type=&company=&from=&to=, stage volumes and
 * bottlenecks. Anyone designing flows (`core.workflow.*`) or holding a
 * document type's view or act permission anywhere; the numbers count only
 * the documents they may see (WorkflowInsights). `type` is a type they may
 * look at, `company` a company they reach (the documents of that company,
 * in its flow and the flow for every company). `from` and `to` are days
 * (default: the last 30 days), read in that company's time zone, else UTC;
 * at most 366 days.
 */
class WorkflowInsightsRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    public const DEFAULT_DAYS = 30;

    public function authorize(): bool
    {
        return $this->types() !== [];
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'nullable', 'string', Rule::in(array_map(fn (DocumentType $type) => $type->key(), $this->types()))],
            // Row-level security limits this to the tenant's companies; reach is checked below.
            'company' => ['bail', 'sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id'), function (string $attribute, mixed $value, Closure $fail) {
                if (! $this->reachesCompany((string) $value)) {
                    $fail(__('validation.exists', ['attribute' => __('workflow.attributes.company_id')]));
                }
            }],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['bail', 'sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, Closure $fail) {
                $from = $this->input('from');

                if (is_string($from) && is_string($value) && strtotime($from) !== false && strtotime($value) !== false
                    && (strtotime($value) - strtotime($from)) / 86400 >= self::MAX_DAYS) {
                    $fail(__('workflow.errors.insights_period', ['days' => self::MAX_DAYS]));
                }
            }],
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => __('workflow.attributes.document_type'),
            'company' => __('workflow.attributes.company_id'),
            'from' => __('workflow.attributes.from_date'),
            'to' => __('workflow.attributes.to_date'),
        ];
    }

    /** @return list<DocumentType> */
    public function types(): array
    {
        return app(WorkflowInsights::class)->typesFor($this->user());
    }

    public function timezone(): string
    {
        $company = $this->validated('company');

        return ($company === null ? null : Company::query()->whereKey($company)->value('timezone')) ?: 'UTC';
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the period's first and last instant */
    public function period(): array
    {
        $zone = $this->timezone();
        $to = $this->validated('to');
        $to = $to === null ? CarbonImmutable::now($zone)->endOfDay() : CarbonImmutable::createFromFormat('Y-m-d', $to, $zone)->endOfDay();
        $from = $this->validated('from');
        $from = $from === null ? $to->subDays(self::DEFAULT_DAYS - 1)->startOfDay() : CarbonImmutable::createFromFormat('Y-m-d', $from, $zone)->startOfDay();

        return [$from->utc(), $to->utc()];
    }

    private function reachesCompany(string $id): bool
    {
        $company = Company::query()->find($id);

        if ($company === null) {
            return false;
        }

        $permissions = WorkflowAccess::PERMISSIONS;

        foreach ($this->types() as $type) {
            array_push($permissions, $type->viewPermission(), $type->actPermission());
        }

        return app(CompanyReach::class)->reaches($this->user(), $company, array_values(array_unique($permissions)));
    }
}
