<?php

namespace App\Core\Billing\Console;

use App\Core\Billing\Operator\Operator;
use App\Core\Currency\TenantCurrencies;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Identity\Services\SignUp;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Platform\PlatformAuditor;
use App\Core\Tenancy\Events\CompanyCreated;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SUB-03 (ADR 016): set up the operator, the platform's seller, as one
 * internal tenant with a selling company per country (`--country=KE
 * --country=CD`). Idempotent: an existing operator keeps its id and gains
 * only the countries it lacks. Each company gets its country pack's tax
 * codes and default payment methods, like any company; its fiscal settings
 * and payment credentials are entered later (owner input,
 * docs/integrations.md).
 *
 * `--fake` (local and testing only) switches the companies' fiscal
 * transmission on with the fake driver and their wallets and card method
 * on, so billing can be tried end to end without credentials.
 *
 * Runs on the runtime connection, in the operator's own context.
 */
class CreateOperator extends Command
{
    protected $signature = 'platform:operator
        {--country=* : Countries to sell in (KE, CD)}
        {--name= : The operator\'s name (defaults to APP_NAME)}
        {--fake : Local and testing only: fake fiscal transmission and payment methods switched on}';

    protected $description = 'Create or extend the operator tenant that issues the platform\'s invoices';

    public function handle(Operator $operator, TenantContext $tenants, TenantCurrencies $currencies, PlatformAuditor $auditor): int
    {
        $countries = array_values(array_unique(array_map('strtoupper', (array) $this->option('country'))));

        if ($countries === [] || array_diff($countries, array_keys(SignUp::COUNTRIES)) !== []) {
            $this->error('Give one or more countries with --country (KE, CD).');

            return self::FAILURE;
        }

        if ($this->option('fake') && ! app()->environment(['local', 'testing'])) {
            $this->error('--fake is only available in local and testing.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: config('app.name')));
        $operator->forget();
        $id = $operator->id();
        $created = $id === null;
        $id ??= (string) Str::uuid7();

        $added = $tenants->run($id, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($id, $created, $name, $countries, $currencies) {
            if ($created) {
                $tenant = Tenant::provision(['id' => $id, 'name' => $name, 'default_locale' => 'en']);
                $tenant->forceFill(['is_operator' => true])->save();
            }

            $added = [];

            foreach ($countries as $country) {
                if (Company::query()->where('country', $country)->whereNull('archived_at')->exists()) {
                    continue;
                }

                $defaults = SignUp::COUNTRIES[$country];
                $company = Company::create([
                    'name' => "{$name} {$country}",
                    'legal_name' => "{$name} {$country}",
                    'country' => $country,
                    'base_currency' => $defaults['currency'],
                    'fiscal_year_start_month' => 1,
                    'timezone' => $defaults['timezone'],
                ]);
                $currencies->provisionFor($company);
                // CP-01, MD-04: tax codes and payment methods like any company.
                event(new CompanyCreated($company));
                $added[] = $country;
            }

            return $added;
        }));

        $operator->forget();

        if ($this->option('fake')) {
            $tenants->run($id, function () {
                foreach (Company::query()->whereNull('archived_at')->get() as $company) {
                    FiscalSettings::query()->firstOrNew(['company_id' => $company->id])
                        ->fill(['country' => $company->country, 'driver' => 'fake', 'tin' => 'P000000000T', 'enabled' => true])->save();

                    PaymentMethod::query()->where('company_id', $company->id)->whereIn('type', PaymentMethod::PROVIDER_TYPES)->get()
                        ->each(fn (PaymentMethod $method) => $method->fill(['active' => true])->save());
                }
            });
        }

        $auditor->record('platform.operator.'.($created ? 'create' : 'extend'), null, null, ['tenant_id' => $id, 'countries' => $added, 'fake' => (bool) $this->option('fake')]);

        $this->info(($created ? 'Operator created: ' : 'Operator: ').$id);
        $this->line($added === [] ? 'No new selling company.' : 'Selling companies added: '.implode(', ', $added).'.');

        return self::SUCCESS;
    }
}
