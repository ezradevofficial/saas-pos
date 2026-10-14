<?php

namespace Modules\Accounting\Chart;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSettings;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Posting\PostingDefaults;

/**
 * ACC-01, ACC-02: a company's chart and settings.
 *
 * - fromTemplate(): the chart is created once from the company's country
 *   template (or a named one), with names in the tenant's language, the
 *   settings' roles pointed at the template's accounts, and the
 *   template's default posting rules (ACC-24).
 * - settings(): the company's settings row (created empty when missing).
 * - Account rules: a code is unique in the company; a parent is a header
 *   of the same company and never the account or one beneath it; an
 *   account with postings keeps its type, currency and postable flag.
 */
class Charts
{
    public function __construct(
        private readonly ChartTemplates $templates,
        private readonly PostingDefaults $defaults,
    ) {}

    public function fromTemplate(Company $company, ?string $template = null): AccountingSettings
    {
        $template ??= (string) $company->country;

        if (! $this->templates->exists($template)) {
            throw LedgerError::make('template_unknown', ['template' => $template], 'template');
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $template) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['acc_chart:'.$company->id]);

            if (Account::query()->where('company_id', $company->id)->exists()) {
                throw LedgerError::make('chart_exists');
            }

            $data = $this->templates->get($template);
            $locale = Tenant::query()->whereKey($company->tenant_id)->value('default_locale') ?: 'en';
            $ids = [];

            foreach ($data['accounts'] as $row) {
                $account = Account::query()->create([
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
                ]);
                $ids[$row['code']] = $account->id;
            }

            $settings = $this->settings($company);
            $roles = ['template' => $template];

            foreach (AccountingSettings::ROLES as $role) {
                $roles["{$role}_account_id"] = $ids[$data['roles'][$role]];
            }

            $settings->fill($roles)->save();
            // ACC-24: the template's default posting rules come with the chart.
            $this->defaults->seed($company);

            return $settings;
        });
    }

    public function settings(Company $company): AccountingSettings
    {
        return AccountingSettings::query()->firstOrCreate(['company_id' => $company->id]);
    }

    /** The account set for $role, or a refusal naming the missing setting. */
    public function accountFor(string $companyId, string $role): string
    {
        return AccountingSettings::query()->where('company_id', $companyId)->value("{$role}_account_id")
            ?? throw LedgerError::make('setting_missing', ['role' => __("accounting.roles.{$role}")]);
    }

    public function hasPostings(Account $account): bool
    {
        return JournalLine::query()->where('account_id', $account->id)->exists();
    }

    /** A parent must be a header of the same company, and not the account itself or beneath it. */
    public function assertParent(Account $account, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Account::query()->where('company_id', $account->company_id)->find($parentId);

        if ($parent === null || $parent->postable) {
            throw LedgerError::make('parent_not_header', field: 'parent_id');
        }

        for ($node = $parent, $depth = 0; $node !== null && $depth < 50; $node = $node->parent_id === null ? null : Account::query()->find($node->parent_id), $depth++) {
            if ($account->exists && $node->id === $account->id) {
                throw LedgerError::make('parent_cycle', field: 'parent_id');
            }
        }
    }
}
