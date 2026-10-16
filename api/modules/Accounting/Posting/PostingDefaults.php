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
 * a person asks for the defaults again). Template accounts are matched by
 * their template code; a rule naming one the chart cannot resolve (its
 * code taken by an account of the tenant's own) is reported in
 * `unresolved`, not saved.
 *
 * With $module (a module switched on, review item 13), only that module's
 * event types (`{module}.…`) that have no rule at all, archived ones
 * included, are seeded, with only the template accounts those rules name
 * (and their parents): what the tenant set up or archived stays as it is.
 */
class PostingDefaults
{
    public function __construct(private readonly ChartTemplates $templates) {}

    /** @return array{accounts: int, rules: int, unresolved: list<array{event_type: string, account: string}>} what was added, and what could not be */
    public function seed(Company $company, ?User $by = null, ?string $module = null): array
    {
        $template = AccountingSettings::query()->where('company_id', $company->id)->value('template') ?: (string) $company->country;

        if (! $this->templates->exists($template)) {
            throw LedgerError::make('template_unknown', ['template' => $template], 'template');
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $template, $by, $module) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['acc_chart:'.$company->id]);

            if (! Account::query()->where('company_id', $company->id)->exists()) {
                throw LedgerError::make('chart_missing');
            }

            $data = $this->templates->get($template);

            if ($module !== null) {
                $data = $this->onlyMissing($company, $data, $module);
            }
            // Review #11: template accounts are known by their template code (a code the tenant renumbered is the same account).
            $ids = Account::query()->where('company_id', $company->id)->whereNotNull('template_code')->pluck('id', 'template_code')->all();
            $codes = Account::query()->where('company_id', $company->id)->pluck('id', 'code')->all();
            $locale = Tenant::query()->whereKey($company->tenant_id)->value('default_locale') ?: 'en';
            $accounts = 0;

            foreach ($data['accounts'] as $row) {
                // Present already, its parent missing, or its code taken by an account of the tenant's own: not created.
                if (isset($ids[$row['code']]) || isset($codes[$row['code']]) || ($row['parent'] !== null && ! isset($ids[$row['parent']]))) {
                    continue;
                }

                $ids[$row['code']] = $codes[$row['code']] = Account::query()->create([
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
            $unresolved = [];

            foreach ($data['posting_rules'] ?? [] as $type => $lines) {
                if (PostingRule::query()->where('company_id', $company->id)->where('event_type', $type)->whereNull('archived_at')->exists()) {
                    continue;
                }

                $missing = [];
                $resolve = function (string $code) use ($ids, &$missing): ?string {
                    if (! isset($ids[$code])) {
                        $missing[] = $code;
                    }

                    return $ids[$code] ?? null;
                };
                $resolved = array_map(fn (array $line) => array_filter([
                    'field' => $line['field'],
                    'side' => $line['side'],
                    'account_id' => isset($line['account']) ? $resolve($line['account']) : null,
                    'party' => (bool) ($line['party'] ?? false),
                    'by' => $line['by'] ?? null,
                    'accounts' => isset($line['accounts']) ? array_map($resolve, $line['accounts']) : null,
                ], fn ($value) => $value !== null), $lines);

                // A rule naming an account the chart cannot resolve is reported, never saved without it.
                if ($missing !== []) {
                    foreach (array_values(array_unique($missing)) as $code) {
                        $unresolved[] = ['event_type' => $type, 'account' => $code];
                    }

                    continue;
                }

                PostingRule::query()->create([
                    'company_id' => $company->id,
                    'event_type' => $type,
                    'description' => __('accounting.rules.default_description'),
                    'lines' => array_values($resolved),
                    'created_by' => $by?->id,
                ]);
                $rules++;
            }

            return ['accounts' => $accounts, 'rules' => $rules, 'unresolved' => $unresolved];
        });
    }

    /**
     * The template narrowed to $module's event types without any rule in
     * the company, and to the accounts they name and those accounts' parents.
     *
     * @param  array{accounts: list<array<string, mixed>>, posting_rules?: array<string, list<array<string, mixed>>>}  $data
     * @return array{accounts: list<array<string, mixed>>, posting_rules: array<string, list<array<string, mixed>>>}
     */
    private function onlyMissing(Company $company, array $data, string $module): array
    {
        $existing = PostingRule::query()->where('company_id', $company->id)->pluck('event_type')->all();
        $rules = array_filter(
            $data['posting_rules'] ?? [],
            fn (string $type) => str_starts_with($type, $module.'.') && ! in_array($type, $existing, true),
            ARRAY_FILTER_USE_KEY,
        );
        $parents = array_column($data['accounts'], 'parent', 'code');
        $needed = [];

        foreach ($rules as $lines) {
            foreach ($lines as $line) {
                foreach ([...(isset($line['account']) ? [$line['account']] : []), ...($line['accounts'] ?? [])] as $code) {
                    for ($at = $code; $at !== null && ! isset($needed[$at]); $at = $parents[$at] ?? null) {
                        $needed[$at] = true;
                    }
                }
            }
        }

        return [
            'accounts' => array_values(array_filter($data['accounts'], fn (array $row) => isset($needed[$row['code']]))),
            'posting_rules' => $rules,
        ];
    }
}
