<?php

namespace Modules\Accounting\Posting;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Chart\ChartTemplates;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSettings;
use Modules\Accounting\Models\PostingRule;

/**
 * ACC-24: a company's default posting rules, from its country chart
 * template (`posting_rules`, account codes only; country-pack data).
 *
 * seed() adds what the company is missing and changes nothing it has:
 * the template's accounts whose code the chart does not have yet (a chart
 * made before the integrations existed), then a rule for every event type
 * without an active rule. A rule the tenant archived on purpose is not
 * brought back by the chart creation (rules are only seeded then and when
 * a person asks for the defaults again).
 */
class PostingDefaults
{
    public function __construct(private readonly ChartTemplates $templates) {}

    /** @return array{accounts: int, rules: int} what was added */
    public function seed(Company $company, ?User $by = null): array
    {
        $template = AccountingSettings::query()->where('company_id', $company->id)->value('template') ?: (string) $company->country;

        if (! $this->templates->exists($template)) {
            throw LedgerError::make('template_unknown', ['template' => $template], 'template');
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $template, $by) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['acc_chart:'.$company->id]);

            if (! Account::query()->where('company_id', $company->id)->exists()) {
                throw LedgerError::make('chart_missing');
            }

            $data = $this->templates->get($template);
            $ids = Account::query()->where('company_id', $company->id)->pluck('id', 'code')->all();
            $locale = Tenant::query()->whereKey($company->tenant_id)->value('default_locale') ?: 'en';
            $accounts = 0;

            foreach ($data['accounts'] as $row) {
                if (isset($ids[$row['code']]) || ($row['parent'] !== null && ! isset($ids[$row['parent']]))) {
                    continue;
                }

                $ids[$row['code']] = Account::query()->create([
                    'company_id' => $company->id,
                    'code' => $row['code'],
                    'name' => $this->templates->name($template, $row['code'], $locale),
                    'type' => $row['type'],
                    'subtype' => $row['subtype'],
                    'parent_id' => $row['parent'] === null ? null : $ids[$row['parent']],
                    'postable' => (bool) $row['postable'],
                    'is_control' => (bool) ($row['control'] ?? false),
                    'cash_flow' => $row['cash_flow'] ?? null,
                    'template_code' => $row['code'],
                ])->id;
                $accounts++;
            }

            $rules = 0;

            foreach ($data['posting_rules'] ?? [] as $type => $lines) {
                if (PostingRule::query()->where('company_id', $company->id)->where('event_type', $type)->whereNull('archived_at')->exists()) {
                    continue;
                }

                $resolved = array_map(fn (array $line) => array_filter([
                    'field' => $line['field'],
                    'side' => $line['side'],
                    'account_id' => isset($line['account']) ? ($ids[$line['account']] ?? null) : null,
                    'party' => (bool) ($line['party'] ?? false),
                    'by' => $line['by'] ?? null,
                    'accounts' => isset($line['accounts']) ? array_filter(array_map(fn (string $code) => $ids[$code] ?? null, $line['accounts'])) : null,
                ], fn ($value) => $value !== null), $lines);

                PostingRule::query()->create([
                    'company_id' => $company->id,
                    'event_type' => $type,
                    'description' => __('accounting.rules.default_description'),
                    'lines' => array_values($resolved),
                    'created_by' => $by?->id,
                ]);
                $rules++;
            }

            return ['accounts' => $accounts, 'rules' => $rules];
        });
    }
}
