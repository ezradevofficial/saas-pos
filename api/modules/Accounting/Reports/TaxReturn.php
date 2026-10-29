<?php

namespace Modules\Accounting\Reports;

use App\Core\Currency\Money;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\Accounting\Fiscal\AccountingFiscalSource;
use Modules\Accounting\Models\AccountingSettings;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;

/**
 * ACC-36: the tax return of a company for a period, from posted transactions only, in the company's base currency.
 *
 * Output tax is what the company's sales-tax account (ACC-02) holds, input tax what its purchase-tax account holds,
 * both read from posted journal lines dated in the period; the net payable is output less input. Each tax amount is
 * attributed to a core tax code and the rate applied:
 *
 * - posted invoices and credit notes (ACC-09): by their lines, which carry the code, the rate as applied, the taxable
 *   value and the tax (converted to base at the document's rate); a credit note counts negative;
 * - tax lines a module posted from a `tax.{code}` field (POS, Hotel and the others): by the code the line kept; the
 *   rate is the code's rate in force on the journal date. The taxable value is not held per code in those journals, so
 *   it is left out and the row says so;
 * - any other tax line (manual journals, journals posted before the code was kept, a supplier invoice from
 *   Procurement): under no tax code.
 *
 * Whatever is attributed adds up to the ledger's own total of the tax accounts (`reconciled`). Rates are never
 * constants here: they come from the company's tax codes and their effective-dated rates, which the country pack fills.
 */
class TaxReturn
{
    private const CHUNK = 200;

    public const OUTPUT = 'output';

    public const INPUT = 'input';

    /** The statutory form boxes (KRA VAT 3 sections, DRC DGI declaration lines) are not held in the country packs. */
    public const NOTE_BOXES = 'statutory_boxes_unmapped';

    /**
     * The return: rows per direction, tax code and rate, the totals and what could not be worked out.
     *
     * @return array<string, mixed>
     */
    public function summary(Company $company, string $from, string $to): array
    {
        $groups = [];
        $fiscalIds = [];
        $this->walk($company, $from, $to, function (array $entry) use (&$groups, &$fiscalIds) {
            $key = $entry['direction'].'|'.($entry['tax_code_id'] ?? '').'|'.($entry['rate'] ?? '');
            $groups[$key] ??= [
                'direction' => $entry['direction'], 'tax_code_id' => $entry['tax_code_id'], 'rate' => $entry['rate'],
                'taxable_minor' => 0, 'taxable_complete' => true, 'tax_minor' => 0, 'entries' => 0,
            ];
            $groups[$key]['tax_minor'] += $entry['tax_minor'];
            $groups[$key]['entries']++;

            if ($entry['taxable_minor'] === null) {
                $groups[$key]['taxable_complete'] = false;
            } else {
                $groups[$key]['taxable_minor'] += $entry['taxable_minor'];
            }

            if ($entry['document_id'] !== null && $entry['direction'] === self::OUTPUT) {
                $fiscalIds[$entry['document_id']] = true;
            }
        });

        $codes = TaxCode::query()->where('company_id', $company->id)->get()->keyBy('id');
        $rows = array_values(array_map(function (array $group) use ($codes) {
            $code = $group['tax_code_id'] === null ? null : $codes->get($group['tax_code_id']);

            return [
                ...$group,
                'code' => $code?->code,
                'name' => $code?->name,
                'kind' => $code?->kind,
                'rate_needed' => $group['tax_code_id'] !== null && $group['rate'] === null && $code?->kind !== 'exempt',
                'taxable_minor' => $group['taxable_complete'] || $group['taxable_minor'] !== 0 ? $group['taxable_minor'] : null,
            ];
        }, $groups));
        usort($rows, fn (array $a, array $b) => [$a['direction'] === self::OUTPUT ? 0 : 1, $a['code'] === null ? 1 : 0, (string) $a['code'], (float) $a['rate']]
            <=> [$b['direction'] === self::OUTPUT ? 0 : 1, $b['code'] === null ? 1 : 0, (string) $b['code'], (float) $b['rate']]);

        $output = array_sum(array_column(array_filter($rows, fn (array $row) => $row['direction'] === self::OUTPUT), 'tax_minor'));
        $input = array_sum(array_column(array_filter($rows, fn (array $row) => $row['direction'] === self::INPUT), 'tax_minor'));
        $ledger = $this->ledger($company, $from, $to);
        $uncoded = array_sum(array_map(fn (array $row) => abs($row['tax_minor']), array_filter($rows, fn (array $row) => $row['tax_code_id'] === null)));

        $notes = [self::NOTE_BOXES];

        if (array_filter($rows, fn (array $row) => ! $row['taxable_complete'])) {
            $notes[] = 'taxable_value_partial';
        }

        if ($uncoded !== 0) {
            $notes[] = 'no_tax_code';
        }

        if (array_filter($rows, fn (array $row) => $row['rate_needed'])) {
            $notes[] = 'rate_needed';
        }

        if ($ledger['output_minor'] !== $output || $ledger['input_minor'] !== $input) {
            $notes[] = 'not_reconciled';
        }

        return [
            'company' => ['id' => $company->id, 'name' => $company->name, 'country' => $company->country],
            'form' => ['KE' => 'vat_return', 'CD' => 'tva_declaration'][$company->country] ?? 'tax_return',
            'from' => $from,
            'to' => $to,
            'currency' => $company->base_currency,
            'rows' => $rows,
            'totals' => ['output_minor' => $output, 'input_minor' => $input, 'net_payable_minor' => $output - $input],
            'ledger' => $ledger,
            'reconciled' => $ledger['output_minor'] === $output && $ledger['input_minor'] === $input,
            'fiscal' => $this->fiscal($company, array_keys($fiscalIds)),
            'notes' => $notes,
        ];
    }

    /**
     * The documents behind one row: invoices, credit notes and journals, newest last, as a page.
     *
     * @param  string|null  $codeId  a tax code id; the string `none` for lines under no tax code
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function entries(Company $company, string $from, string $to, ?string $direction, ?string $codeId, ?string $rate, int $page, int $perPage): array
    {
        $rows = [];
        $this->walk($company, $from, $to, function (array $entry) use (&$rows, $direction, $codeId, $rate) {
            if (($direction !== null && $entry['direction'] !== $direction)
                || ($codeId !== null && ($codeId === 'none' ? $entry['tax_code_id'] !== null : $entry['tax_code_id'] !== $codeId))
                || ($rate !== null && (string) $entry['rate'] !== ($rate === 'none' ? '' : $rate))) {
                return;
            }

            $rows[] = $entry;
        });

        return ['data' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => count($rows)];
    }

    /**
     * Calls $sink with every tax entry of the period: [direction, tax_code_id, rate, taxable_minor|null, tax_minor
     * (signed, base), journal_id, journal_number, date, source_type, document_id, document_kind, document_number].
     *
     * @param  callable(array<string, mixed>): void  $sink
     */
    public function walk(Company $company, string $from, string $to, callable $sink): void
    {
        $settings = AccountingSettings::query()->where('company_id', $company->id)->first();
        $accounts = array_filter([self::OUTPUT => $settings?->accountFor('sales_tax'), self::INPUT => $settings?->accountFor('purchase_tax')]);
        $inRange = Journal::query()->select('id')->where('company_id', $company->id)->where('status', Journal::POSTED)->whereBetween('date', [$from, $to]);
        $codes = TaxCode::query()->where('company_id', $company->id)->with('rates')->get()->keyBy('id');
        $base = (string) $company->base_currency;

        // Posted invoices and credit notes, by their lines.
        Document::query()->where('company_id', $company->id)->where('status', Document::POSTED)->whereIn('journal_id', $inRange)
            ->orderBy('id')->chunkById(self::CHUNK, function ($documents) use ($sink, $accounts, $base, $codes) {
                $journals = Journal::query()->whereIn('id', $documents->pluck('journal_id'))->get()->keyBy('id');
                $lines = DocumentLine::query()->whereIn('document_id', $documents->modelKeys())->orderBy('line_no')->get()->groupBy('document_id');
                $taxLines = $accounts === [] ? collect() : JournalLine::query()->whereIn('journal_id', $documents->pluck('journal_id'))->whereIn('account_id', array_values($accounts))->get()->groupBy('journal_id');

                foreach ($documents as $document) {
                    $journal = $journals->get($document->journal_id);
                    $direction = $document->side() === 'customer' ? self::OUTPUT : self::INPUT;
                    $sign = $document->isCredit() ? -1 : 1;
                    $fx = $document->fx !== null && ! $document->fx->isIdentity() ? $document->fx : null;
                    $toBase = fn (int $minor) => $fx === null ? $minor : (int) $fx->convert(Money::ofMinor($minor, $document->currency))->minor();
                    $context = [
                        'direction' => $direction, 'journal_id' => $journal->id, 'journal_number' => $journal->number, 'date' => $journal->date->toDateString(),
                        'source_type' => $journal->source_type, 'document_id' => $document->id, 'document_kind' => $document->kind, 'document_number' => $document->number,
                    ];
                    $attributed = 0;

                    foreach ($lines->get($document->id, collect()) as $line) {
                        if ($line->tax_code_id === null) {
                            continue;
                        }

                        $tax = $sign * $toBase((int) $line->tax_minor);
                        $attributed += $tax;
                        $sink([...$context, 'tax_code_id' => $line->tax_code_id, 'rate' => $line->tax_rate === null ? null : $this->rate((string) $line->tax_rate),
                            'taxable_minor' => $sign * $toBase((int) $line->net_minor), 'tax_minor' => $tax]);
                    }

                    // What the journal's tax line holds beyond the lines' own tax (none, unless a journal was changed by hand).
                    $held = $this->signed($taxLines->get($journal->id, collect())->filter(fn ($l) => $l->account_id === ($accounts[$direction] ?? null)), $direction);

                    if ($held - $attributed !== 0) {
                        $sink([...$context, 'tax_code_id' => null, 'rate' => null, 'taxable_minor' => null, 'tax_minor' => $held - $attributed]);
                    }
                }
            });

        if ($accounts === []) {
            return;
        }

        $ofDocuments = Document::query()->select('journal_id')->where('company_id', $company->id)->whereNotNull('journal_id');

        // Tax lines of every other posted journal: modules' coded lines, manual journals, reversals.
        JournalLine::query()->where('company_id', $company->id)->whereIn('account_id', array_values($accounts))->whereIn('journal_id', $inRange)->whereNotIn('journal_id', $ofDocuments)
            ->orderBy('id')->chunkById(self::CHUNK, function ($lines) use ($sink, $accounts, $codes) {
                $journals = Journal::query()->whereIn('id', $lines->pluck('journal_id'))->get()->keyBy('id');

                foreach ($lines as $line) {
                    $journal = $journals->get($line->journal_id);
                    $direction = $line->account_id === $accounts[self::OUTPUT] ? self::OUTPUT : self::INPUT;
                    $tax = $this->signed(collect([$line]), $direction);

                    if ($tax === 0) {
                        continue;
                    }

                    $code = $line->tax_code_id === null ? null : $codes->get($line->tax_code_id);
                    $rate = $code?->rateOn(CarbonImmutable::parse($journal->date->toDateString()))?->rate;
                    $sink([
                        'direction' => $direction, 'tax_code_id' => $code?->id, 'rate' => $rate === null ? null : $this->rate((string) $rate), 'taxable_minor' => null, 'tax_minor' => $tax,
                        'journal_id' => $journal->id, 'journal_number' => $journal->number, 'date' => $journal->date->toDateString(), 'source_type' => $journal->source_type,
                        'document_id' => null, 'document_kind' => null, 'document_number' => null,
                    ]);
                }
            });
    }

    /** The ledger's own totals of the tax accounts over the same posted journals (the figure the return must equal). */
    private function ledger(Company $company, string $from, string $to): array
    {
        $settings = AccountingSettings::query()->where('company_id', $company->id)->first();
        $total = function (?string $account, string $direction) use ($company, $from, $to): int {
            if ($account === null) {
                return 0;
            }

            $row = JournalLine::query()->where('company_id', $company->id)->where('account_id', $account)
                ->whereIn('journal_id', Journal::query()->select('id')->where('company_id', $company->id)->where('status', Journal::POSTED)->whereBetween('date', [$from, $to]))
                ->selectRaw('coalesce(sum(base_debit_minor), 0) as debit, coalesce(sum(base_credit_minor), 0) as credit')->first();

            return $direction === self::OUTPUT ? (int) $row->credit - (int) $row->debit : (int) $row->debit - (int) $row->credit;
        };

        return ['output_minor' => $total($settings?->accountFor('sales_tax'), self::OUTPUT), 'input_minor' => $total($settings?->accountFor('purchase_tax'), self::INPUT)];
    }

    /** How many of the period's customer invoices and credit notes the tax authority has accepted. */
    private function fiscal(Company $company, array $documentIds): array
    {
        $accepted = 0;

        foreach (array_chunk($documentIds, 500) as $ids) {
            $accepted += FiscalSubmission::query()->where('company_id', $company->id)->where('source', AccountingFiscalSource::KEY)->whereIn('document_id', $ids)->where('status', 'accepted')->distinct()->count('document_id');
        }

        return ['documents' => count($documentIds), 'accepted' => $accepted, 'pending' => count($documentIds) - $accepted];
    }

    /** The lines' amount on the account's own side: credit less debit for output, debit less credit for input. */
    private function signed($lines, string $direction): int
    {
        $debit = (int) $lines->sum('base_debit_minor');
        $credit = (int) $lines->sum('base_credit_minor');

        return $direction === self::OUTPUT ? $credit - $debit : $debit - $credit;
    }

    /** A rate as the pack and the tax codes write it ("16", "12.5"): no trailing zeros. */
    private function rate(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
