<?php

namespace Modules\Accounting\Integrations;

use App\Core\Currency\FxSnapshot;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Models\AccountingSettings;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\QueuedSource;
use Modules\Accounting\Posting\PostingResult;
use Modules\Accounting\Posting\PostingService;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Inventory\Events\StockMoved;
use Modules\Loyalty\Events\LoyaltyLedgerEvent;
use Modules\Payroll\Events\PayrollPosted;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\Procurement\Events\GoodsReceiptPosted;
use Modules\Procurement\Events\LandedCostAllocated;
use Modules\Procurement\Events\SupplierInvoiceApproved;
use Modules\Stores\Events\StoreConsumptionValued;

/**
 * ACC-25 to ACC-32 (stage 7B): what other modules' events post, in the
 * current tenant. Nothing happens unless Accounting is active (RBAC-08);
 * the raising module was active when the event happened (PostModuleEvent
 * queues only then), whatever it is now. Every source goes through
 * PostingService and the company's posting rules, idempotently by
 * source; one that cannot post (no rule, no account, a closed period,
 * Essentials with a foreign currency) is kept as a posting exception,
 * never lost.
 *
 * POS sales of a company that posts a daily summary (ACC-26) wait in
 * `acc_source_queue` for their location's summary journal, when they are
 * in the base currency; a sale in another currency posts on its own (its
 * rate is its own). Review #3: a sale posts one way only: one already
 * posted on its own (or kept as an open exception) is never queued, and
 * one queued for a summary is never posted on its own, whatever the mode
 * says when it comes again (a mode switch, a backfill).
 */
class Integrations
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly PostingService $posting,
        private readonly PosSources $pos,
        private readonly InventorySources $inventory,
        private readonly LoyaltySources $loyalty,
        private readonly StoresSources $stores,
        private readonly SupplierInvoices $supplierInvoices,
        private readonly LandedCostSources $landedCosts,
        private readonly PayrollSources $payroll,
    ) {}

    /** Post what $event says (live or replayed by the backfill); null when nothing posts. */
    public function handle(object $event): ?PostingResult
    {
        if (! $this->modules->isActive(AccountingServiceProvider::MODULE)) {
            return null;
        }

        // Stage 7B closing review: the raising module was on when the event happened (PostModuleEvent queues
        // only then, the backfill replays what modules recorded); it may be off by now (decision, owner may change).
        return match (true) {
            $event instanceof SaleCompleted => $event->sale !== [] ? $this->sale($event->sale) : null,
            $event instanceof SaleVoided => $event->sale !== [] ? $this->posting->post($this->pos->void($event->sale, $event->voidId, $event->voidedAt)) : null,
            $event instanceof SaleRefunded => $event->refund !== [] ? $this->posting->post($this->pos->refund($event->refund)) : null,
            $event instanceof StockMoved => $this->post($this->inventory->stockMoved($event)),
            $event instanceof LoyaltyLedgerEvent => $this->post($this->loyalty->entry($event)),
            $event instanceof StoreConsumptionValued => $this->post($this->stores->consumption($event)),
            // ACC-32: an approved Procurement invoice becomes a posted Accounting supplier invoice.
            $event instanceof SupplierInvoiceApproved => $this->supplierInvoices->approved($event),
            // Stage 7B closing review: landed costs clear through their bills (procurement.md decision 19).
            $event instanceof LandedCostAllocated => $this->landedCosts->allocated($event),
            $event instanceof GoodsReceiptPosted => $this->landedCosts->received($event),
            // PAY-22: a posted payroll run, one journal per department and pay currency.
            $event instanceof PayrollPosted => $this->payrollRun($event),
            default => null,
        };
    }

    /**
     * ACC-17 (stage 7B closing review): a posted source's description from
     * the en/fr text of its source type, naming its document; null when the
     * type has none.
     */
    public static function memo(string $sourceType, ?string $reference): ?string
    {
        $key = 'accounting.integrations.memos.'.str_replace('.', '_', $sourceType);
        $text = __($key, ['reference' => (string) $reference]);

        return $text === $key ? null : trim($text);
    }

    /** CUR-04: a rate snapshot as an event carried it. */
    public static function fx(array $fx): FxSnapshot
    {
        return new FxSnapshot((string) $fx['rate'], (string) $fx['base'], (string) $fx['quote'], $fx['kind'] ?? null,
            isset($fx['effective_at']) ? CarbonImmutable::parse($fx['effective_at']) : null);
    }

    private function sale(array $sale): ?PostingResult
    {
        $source = $this->pos->sale($sale);
        $company = Company::query()->find($source->companyId);
        $mode = AccountingSettings::query()->where('company_id', $source->companyId)->value('sales_posting');

        $summarised = $mode === AccountingSettings::DAILY_SUMMARY && $company !== null && $source->currency === $company->base_currency;

        // Review #3: a sale posts one way only, whatever the mode was when it first came (a mode switch, a backfill).
        if ($summarised && $this->postedOnItsOwn($source)) {
            return null;
        }

        if (! $summarised) {
            return QueuedSource::query()->where('company_id', $source->companyId)->where('source_type', $source->sourceType)->where('source_id', $source->sourceId)->exists()
                ? null
                : $this->posting->post($source);
        }

        QueuedSource::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'source_type' => $source->sourceType,
            'source_id' => $source->sourceId,
            'location_id' => $sale['location_id'],
            'local_date' => $source->date->setTimezone($company->timezone ?: 'UTC')->toDateString(),
            'currency' => $source->currency,
            'payload' => json_encode($source->toArray()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return null;
    }

    /** PAY-22: each group of a posted run through the posting rules; the last result (each journal is idempotent by its group). */
    private function payrollRun(PayrollPosted $event): ?PostingResult
    {
        $result = null;

        foreach ($this->payroll->posted($event) as $source) {
            $result = $this->post($source) ?? $result;
        }

        return $result;
    }

    /** A sale already posted on its own, or kept as an open exception to post on its own. */
    private function postedOnItsOwn(SourceDocument $source): bool
    {
        return Journal::query()->where('company_id', $source->companyId)->where('source_type', $source->sourceType)->where('source_id', $source->sourceId)->exists()
            || PostingException::query()->where('company_id', $source->companyId)->where('source_type', $source->sourceType)->where('source_id', $source->sourceId)
                ->where('status', PostingException::OPEN)->exists();
    }

    private function post(?SourceDocument $source): ?PostingResult
    {
        return $source === null || $source->isEmpty() ? null : $this->posting->post($source);
    }
}
