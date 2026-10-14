<?php

namespace Modules\Accounting\Integrations;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Documents\Documents;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;
use Modules\Accounting\Posting\PostingResult;
use Modules\Accounting\Posting\PostingService;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Procurement\Events\SupplierInvoiceApproved;

/**
 * ACC-32 (Procurement, PRC-15, PRC-25; procurement.md decision 3): an
 * approved Procurement supplier invoice becomes a posted Accounting
 * supplier invoice (ACC-09), so payments, allocation, ageing and
 * statements of the payable stay in Accounting.
 *
 * - The journal posts through the company's `procurement.supplier_invoice`
 *   rule (ACC-07), in base currency from the event alone: `grni` (the GRN
 *   value of stock lines, at the receipt's rate, cleared against what
 *   Inventory's receipt journal credited, `inventory.receipt`),
 *   `price_variance` (their invoiced net less that value), `expense` (the
 *   net of service lines, and of stock not tracked by Inventory when it
 *   was received: no receipt journal credited GRNI for it; Procurement
 *   says per line, decision 16), `tax` and `total` (the
 *   payable, with the party, in the invoice currency at the invoice's
 *   rate). Procurement's own GoodsReceiptPosted posts nothing: the GRN's
 *   journal comes from StockMoved (`procurement.goods_receipt`), once.
 * - Then the Accounting document is written as posted, linked to that
 *   journal, numbered BILL, open for the invoice's total in its currency.
 * - Idempotent per invoice: the journal is unique per source and the
 *   document per journal (a unique index); a replay, or two workers at
 *   once, make one of each. A source that cannot post (no rule, a closed
 *   period) is a posting exception like any other; its retry posts the
 *   journal and then writes the document from the kept payload
 *   (`link.document`).
 */
class SupplierInvoices
{
    public const SOURCE = 'procurement.supplier_invoice';

    public function __construct(
        private readonly PostingService $posting,
        private readonly Numbering $numbering,
        private readonly Auditor $auditor,
    ) {}

    public function approved(SupplierInvoiceApproved $event): ?PostingResult
    {
        return $this->post($this->source($event), null);
    }

    /** A kept exception tried again (from the posting exceptions screen). */
    public function retry(PostingException $exception, ?User $by): PostingResult
    {
        return $this->post(SourceDocument::fromArray($exception->payload), $by);
    }

    public function source(SupplierInvoiceApproved $event): SourceDocument
    {
        $document = $event->document;
        $grni = BigInteger::zero();
        $variance = BigInteger::zero();
        $expense = BigInteger::zero();

        // Decision 16 (procurement.md): Procurement says per line what met GRNI (its tracked stock share), so the
        // posting never depends on which modules are active now; the rest of the net is purchases.
        foreach ($document['lines'] as $line) {
            $net = BigInteger::of((string) $line['base_net_minor']);
            $stock = ($line['kind'] ?? null) === 'stock';
            $stockNet = $stock ? BigInteger::of((string) ($line['base_stock_net_minor'] ?? $line['base_net_minor'])) : BigInteger::zero();
            $lineGrni = $stock ? BigInteger::of((string) $line['base_grni_minor']) : BigInteger::zero();
            $grni = $grni->plus($lineGrni);
            $variance = $variance->plus($stockNet->minus($lineGrni));
            $expense = $expense->plus($net->minus($stockNet));
        }

        $amounts = array_filter([
            'grni' => (string) $grni,
            'price_variance' => (string) $variance,
            'expense' => (string) $expense,
            'tax' => (string) $event->amounts['tax'],
            'total' => (string) $event->amounts['total'],
        ], fn (string $minor) => $minor !== '0');
        $foreign = $document['currency'] === $event->currency ? [] : ['total' => [
            'currency' => $document['currency'],
            'amount_minor' => (string) $document['totals']['total'],
            'base_minor' => (string) $event->amounts['total'],
            'fx' => $event->fx,
        ]];

        return new SourceDocument(
            companyId: $event->companyId,
            sourceType: self::SOURCE,
            sourceId: $event->invoiceId,
            date: CarbonImmutable::parse($event->date),
            currency: $event->currency,
            amounts: $amounts,
            fx: null,
            dimensions: array_filter([
                'party_id' => $event->dimensions['party_id'] ?? null, 'branch_id' => $event->dimensions['branch_id'] ?? null,
                'cost_centre_id' => $event->dimensions['cost_centre_id'] ?? null, 'project_id' => $event->dimensions['project_id'] ?? null,
            ]),
            reference: mb_substr($event->reference, 0, 80),
            link: ['kind' => 'procurement.invoice', 'id' => $event->invoiceId, 'document' => [...$document, 'fx' => $event->fx]],
            foreign: $foreign,
        );
    }

    private function post(SourceDocument $source, ?User $by): PostingResult
    {
        $result = $this->posting->post($source, $by);

        if ($result->journal !== null && is_array($source->link['document'] ?? null)) {
            $this->document($result->journal, $source->link['document'], $source);
        }

        return $result;
    }

    /** The posted Accounting supplier invoice of $journal (written once). */
    private function document(Journal $journal, array $data, SourceDocument $source): Document
    {
        if ($existing = Document::query()->where('journal_id', $journal->id)->first()) {
            return $existing;
        }

        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($journal, $data, $source) {
                $company = Company::query()->findOrFail($journal->company_id);
                $rule = PostingRule::query()->where('company_id', $company->id)->where('event_type', self::SOURCE)->whereNull('archived_at')->first();
                $accounts = collect($rule?->lines ?? [])->pluck('account_id', 'field');
                $issue = CarbonImmutable::parse($data['invoice_date']);
                $due = isset($data['due_date']) && $data['due_date'] >= $data['invoice_date'] ? $data['due_date'] : $issue->toDateString();
                $branch = $data['branch_id'] === null ? null : Branch::query()->find($data['branch_id']);
                [$type] = Documents::NUMBERING['supplier_invoice'];
                $number = $this->numbering->next($type, new NumberContext($company, $branch, at: $issue->setTime(12, 0)));
                $total = (int) $data['totals']['total'];

                $document = Document::query()->create([
                    'company_id' => $company->id,
                    'branch_id' => $branch?->id,
                    'kind' => 'supplier_invoice',
                    'party_id' => $data['party_id'],
                    'number' => $number->number,
                    'supplier_reference' => $data['supplier_reference'] ?? null,
                    'issue_date' => $issue->toDateString(),
                    'due_date' => $due,
                    'currency' => $data['currency'],
                    'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? false),
                    'fx' => Integrations::fx($data['fx']),
                    'base_currency' => $source->currency,
                    'subtotal_minor' => (int) $data['totals']['net'],
                    'tax_minor' => (int) $data['totals']['tax'],
                    'total_minor' => $total,
                    'base_subtotal_minor' => (int) ($data['base_totals']['net'] ?? 0),
                    'base_tax_minor' => (int) ($data['base_totals']['tax'] ?? 0),
                    'base_total_minor' => (int) ($data['base_totals']['total'] ?? 0),
                    'open_minor' => $total,
                    'status' => Document::POSTED,
                    'journal_id' => $journal->id,
                    'memo' => __('accounting.integrations.procurement_invoice', ['number' => $data['number'] ?? '']),
                    'posted_at' => CarbonImmutable::now(),
                ]);

                foreach (array_values($data['lines']) as $index => $line) {
                    // The line's account is where its net went: GRNI for received stock, else the purchases expense.
                    $field = ($line['kind'] ?? null) === 'stock' ? 'grni' : 'expense';
                    DocumentLine::query()->create([
                        'document_id' => $document->id,
                        'line_no' => $index + 1,
                        'description' => mb_substr((string) $line['description'], 0, 255),
                        'item_id' => $line['item_id'] ?? null,
                        'account_id' => $accounts[$field] ?? $accounts['expense'] ?? $accounts['grni'],
                        'qty' => (string) BigDecimal::of((string) $line['qty'])->toScale(4, RoundingMode::HalfUp),
                        'unit_price_minor' => (int) $line['unit_price_minor'],
                        'tax_code_id' => $line['tax_code_id'] ?? null,
                        'tax_rate' => $line['tax_rate'] ?? null,
                        'net_minor' => (int) $line['net_minor'],
                        'tax_minor' => (int) $line['tax_minor'],
                        'total_minor' => (int) $line['total_minor'],
                        'cost_centre_id' => $line['cost_centre_id'] ?? null,
                        'project_id' => $line['project_id'] ?? null,
                    ]);
                }

                $this->auditor->record('accounting.document.post', $document, null, ['number' => $number->number, 'journal' => $journal->id, 'source' => self::SOURCE]);

                return $document;
            });
        } catch (UniqueConstraintViolationException) {
            // Another worker wrote it first.
            return Document::query()->where('journal_id', $journal->id)->firstOrFail();
        }
    }
}
