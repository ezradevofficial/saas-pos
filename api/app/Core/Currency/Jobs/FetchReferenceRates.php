<?php

namespace App\Core\Currency\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Currency\Feeds\FeedNotConfigured;
use App\Core\Currency\Feeds\RateFeeds;
use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CUR-03: load one company's reference rates for a day from its feed
 * (`companies.rate_feed`), in the company's tenant (row-level security).
 * Quotes are the tenant's other active currencies; each rate is stored as
 * 1 base = mid quote, kind `reference`, unless that rate is already there.
 * A feed without an endpoint is logged and skipped. Dispatched daily by
 * `exchange-rates:fetch`.
 */
class FetchReferenceRates implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $date,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(RateFeeds $feeds, AuditContext $audit): void
    {
        $company = Company::query()->whereKey($this->companyId)->whereNull('archived_at')->first();

        if ($company === null || $company->rate_feed === 'none') {
            return;
        }

        $base = $company->base_currency;
        $quotes = TenantCurrency::query()->where('active', true)->where('code', '!=', $base)->orderBy('code')->pluck('code')->all();

        if ($quotes === []) {
            return;
        }

        try {
            $rates = $feeds->driver($company->rate_feed)->fetch($base, $quotes, CarbonImmutable::parse($this->date));
        } catch (FeedNotConfigured $e) {
            Log::info('Reference rates skipped: '.$e->getMessage(), ['company_id' => $company->id, 'feed' => $company->rate_feed]);

            return;
        }

        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        DB::transaction(function () use ($rates, $company, $base, $quotes) {
            foreach ($rates as $rate) {
                if ($rate->base !== $base || ! in_array($rate->quote, $quotes, true)) {
                    continue;
                }

                $key = ['company_id' => $company->id, 'base' => $rate->base, 'quote' => $rate->quote, 'kind' => 'reference', 'effective_at' => $rate->effectiveAt];

                if (ExchangeRate::query()->where($key)->exists()) {
                    continue;
                }

                ExchangeRate::create($key + [
                    'mid' => $rate->mid,
                    'buy' => $rate->buy,
                    'sell' => $rate->sell,
                    'source' => $company->rate_feed,
                ]);
            }
        });
    }
}
