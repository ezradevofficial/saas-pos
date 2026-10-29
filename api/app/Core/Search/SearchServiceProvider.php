<?php

namespace App\Core\Search;

use App\Core\Search\Searchers\BillingInvoiceSearcher;
use App\Core\Search\Searchers\ItemSearcher;
use App\Core\Search\Searchers\PartySearcher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** LAY-01: the record search of the quick search; modules add their searchers to RecordSearch. */
class SearchServiceProvider extends ServiceProvider
{
    public const LIMITER = 'record-search';

    public function register(): void
    {
        $this->app->singleton(RecordSearch::class);
    }

    public function boot(): void
    {
        // The palette searches as the user types (debounced): a generous limit per user, a hard one per address.
        RateLimiter::for(self::LIMITER, fn (Request $request) => [
            Limit::perMinute(60)->by('user|'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(240)->by('ip|'.$request->ip()),
        ]);

        $search = $this->app->make(RecordSearch::class);
        $search->register(new PartySearcher('customer'));
        $search->register(new PartySearcher('supplier'));
        $search->register(new ItemSearcher);
        $search->register(new BillingInvoiceSearcher);
    }
}
