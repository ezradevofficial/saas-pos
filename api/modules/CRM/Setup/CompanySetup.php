<?php

namespace Modules\CRM\Setup;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Option;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\Settings;
use Modules\CRM\Models\Stage;
use Modules\CRM\Support\Tx;

/**
 * CRM-01 to CRM-03: a company's first use of CRM seeds its settings, a
 * default pipeline and the lists of sources and reasons, named in the
 * tenant's default language and stored once (business records have one
 * name, owner decision 2026-10-08). Idempotent: a company that has any
 * pipeline (archived ones too) or any option of a kind gets none again;
 * a company with settings was seeded with them (one transaction).
 */
class CompanySetup
{
    public const STAGES = ['qualification' => 10, 'needs_analysis' => 25, 'proposal' => 50, 'negotiation' => 75];

    public const OPTIONS = [
        Option::SOURCE => ['website', 'referral', 'walk_in', 'phone', 'social_media', 'event'],
        Option::WON_REASON => ['price', 'relationship', 'product_fit', 'service'],
        Option::LOST_REASON => ['price', 'competitor', 'no_budget', 'no_decision', 'timing'],
    ];

    public function ensure(Company $company): Settings
    {
        // Seeded with its settings, in one transaction: a company with settings has its pipeline and lists.
        if (($settings = Settings::query()->where('company_id', $company->id)->first()) !== null) {
            return $settings;
        }

        return Tx::run(function () use ($company) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['crm_setup:'.$company->id]);
            $settings = Settings::query()->where('company_id', $company->id)->first()
                ?? Settings::query()->create(['company_id' => $company->id]);
            $locale = Tenant::query()->whereKey($company->tenant_id)->value('default_locale') ?: 'en';

            if (! Pipeline::query()->where('company_id', $company->id)->exists()) {
                $pipeline = Pipeline::query()->create(['company_id' => $company->id, 'name' => __('crm.defaults.pipeline', [], $locale), 'is_default' => true]);
                $position = 0;

                foreach (self::STAGES as $key => $probability) {
                    Stage::query()->create(['pipeline_id' => $pipeline->id, 'name' => __("crm.defaults.stages.{$key}", [], $locale), 'position' => ++$position, 'probability' => $probability]);
                }
            }

            foreach (self::OPTIONS as $kind => $keys) {
                if (Option::query()->where('company_id', $company->id)->where('kind', $kind)->exists()) {
                    continue;
                }

                foreach (array_values($keys) as $index => $key) {
                    Option::query()->create(['company_id' => $company->id, 'kind' => $kind, 'name' => __("crm.defaults.{$kind}.{$key}", [], $locale), 'position' => $index + 1]);
                }
            }

            return $settings;
        });
    }
}
