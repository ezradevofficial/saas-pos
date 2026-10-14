<?php

namespace Modules\Inventory\Events;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Modules\Inventory\Models\Movement;

/**
 * Stage 7B (core EventReplays): StockMoved raised again for the postings
 * of a period. A posting is its source and time: every movement of one
 * posting shares the source and `occurred_at`, and two postings of one
 * source (a transfer's dispatch and receipt) never do.
 */
final class StockEventReplay
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @param Closure(object): void $emit */
    public function __invoke(CarbonImmutable $from, CarbonImmutable $to, Closure $emit): void
    {
        $tenant = $this->tenants->require();
        $postings = Movement::query()->whereBetween('occurred_at', [$from, $to])
            ->select(['company_id', 'currency', 'source_type', 'source_id', 'occurred_at'])->distinct()->orderBy('occurred_at')->get();

        foreach ($postings as $posting) {
            $movements = Movement::query()->where('source_type', $posting->source_type)->where('source_id', $posting->source_id)
                ->where('occurred_at', $posting->getRawOriginal('occurred_at'))->orderBy('created_at')->orderBy('id')->get()->all();
            $emit(StockMoved::of($tenant, $posting->company_id, $posting->currency, $posting->source_type, $posting->source_id, $movements, CarbonImmutable::parse($posting->getRawOriginal('occurred_at'))));
        }
    }
}
