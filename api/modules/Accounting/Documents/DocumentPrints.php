<?php

namespace Modules\Accounting\Documents;

use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\FiscalRules;
use App\Core\Fiscal\FiscalQueue;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Modules\Accounting\Fiscal\AccountingFiscalSource;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;

/**
 * ACC-19, TPL-01: the data of an invoice, credit note or party statement
 * for its document template, in the shape every template reads
 * (DataSource): money as `{minor, currency}`, business names as entered,
 * and the tax authority's answer when the company's country pack requires
 * the fiscal block (TPL-03).
 */
class DocumentPrints
{
    public const TYPES = [
        'customer_invoice' => 'accounting.invoice',
        'supplier_invoice' => 'accounting.invoice',
        'customer_credit' => 'accounting.credit_note',
        'supplier_credit' => 'accounting.credit_note',
    ];

    public const COLUMNS = ['description' => 'text', 'item_code' => 'text', 'qty' => 'qty', 'unit_price' => 'money', 'tax_rate' => 'rate', 'tax' => 'money', 'net' => 'money', 'total' => 'money'];

    public function __construct(
        private readonly FiscalRules $rules,
        private readonly FiscalQueue $queue,
        private readonly CurrencyDecimals $decimals,
    ) {}

    public function document(Document $document): DocumentData
    {
        $type = self::TYPES[$document->kind];
        $company = Company::query()->findOrFail($document->company_id);
        $branch = $document->branch_id === null ? null : Branch::query()->find($document->branch_id);
        $party = Party::query()->find($document->party_id);
        $currency = $document->currency;
        $lines = DocumentLine::query()->where('document_id', $document->id)->orderBy('line_no')->get();
        $codes = Item::query()->whereIn('id', $lines->pluck('item_id')->filter()->unique())->pluck('code', 'id');
        $fiscal = str_starts_with($document->kind, 'customer') ? $this->fiscal($type, $company, $document) : null;

        $data = [
            ...$this->common($company, $branch, $party),
            'currencies' => [$currency => $this->decimals->for($currency)] + ($document->base_currency === null ? [] : [$document->base_currency => $this->decimals->for($document->base_currency)]),
            'document' => [
                'number' => $document->number ?? '',
                'date' => $document->issue_date->toDateString(),
                'due_date' => $document->due_date->toDateString(),
                'currency' => $currency,
                'reference' => $document->credits_document_id === null ? $document->supplier_reference : (string) Document::query()->whereKey($document->credits_document_id)->value('number'),
                'memo' => $document->memo,
            ],
            'lines' => $lines->map(fn (DocumentLine $line) => [
                'description' => $line->description,
                'item_code' => $line->item_id === null ? null : ($codes[$line->item_id] ?? null),
                'qty' => self::decimal((string) $line->qty),
                'unit_price' => $this->money($line->unit_price_minor, $currency),
                'tax_rate' => $line->tax_rate === null ? null : self::decimal((string) $line->tax_rate),
                'tax' => $this->money($line->tax_minor, $currency),
                'net' => $this->money($line->net_minor, $currency),
                'total' => $this->money($line->total_minor, $currency),
                'custom' => [],
            ])->values()->all(),
            'totals' => [
                'subtotal' => $this->money($document->subtotal_minor, $currency),
                'discount' => $this->money(0, $currency),
                'tax' => $this->money($document->tax_minor, $currency),
                'total' => $this->money($document->total_minor, $currency),
                'dual' => $document->base_currency !== null && $document->base_currency !== $currency ? $this->money($document->base_total_minor, $document->base_currency) : null,
                'tax_lines' => $this->taxLines($lines, $currency),
            ],
            'payments' => [],
            'change' => null,
            'fiscal' => $fiscal,
        ];

        return new DocumentData($type, $document->id, $data, $company->id, $branch?->id, $company->country, $this->email($party));
    }

    /** @param array<string, mixed> $statement LedgerReports::partyStatement() */
    public function statement(Company $company, Party $party, array $statement): DocumentData
    {
        $currency = $statement['currency'];

        $data = [
            ...$this->common($company, null, $party),
            'currencies' => [$currency => $this->decimals->for($currency)],
            'document' => [
                'number' => '',
                'date' => $statement['to'],
                'currency' => $currency,
                'from' => $statement['from'],
                'to' => $statement['to'],
                'opening' => $this->money($statement['opening'], $currency),
                'closing' => $this->money($statement['closing'], $currency),
            ],
            'lines' => array_map(fn (array $entry) => [
                'date' => $entry['date'],
                'number' => $entry['number'],
                'type' => __('accounting.statement_types.'.$entry['type']),
                'due_date' => $entry['due_date'],
                'amount' => $this->money($entry['amount'], $currency),
                'balance' => $this->money($entry['balance'], $currency),
                'custom' => [],
            ], $statement['entries']),
            'totals' => ['subtotal' => $this->money($statement['opening'], $currency), 'discount' => $this->money(0, $currency), 'tax' => $this->money(0, $currency),
                'total' => $this->money($statement['closing'], $currency), 'dual' => null, 'tax_lines' => []],
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];

        return new DocumentData('accounting.statement', $party->id, $data, $company->id, null, $company->country, $this->email($party));
    }

    private function common(Company $company, ?Branch $branch, ?Party $party): array
    {
        return [
            'company' => [
                'name' => $company->name, 'legal_name' => $company->legal_name ?: $company->name, 'tax_id' => $company->tax_id,
                'address' => is_string($company->address) ? $company->address : null, 'phone' => null, 'email' => null,
                'country' => $company->country, 'logo' => null, 'custom' => [],
            ],
            'branch' => $branch === null ? null : ['name' => $branch->name, 'code' => $branch->code, 'address' => null],
            'location' => null,
            'customer' => $party === null ? null : [
                'name' => $party->name, 'tax_id' => $party->tax_id, 'phone' => $party->phoneNumbers()[0] ?? null,
                'email' => $this->email($party), 'address' => null, 'tags' => array_values((array) $party->tags), 'custom' => [],
            ],
        ];
    }

    /** TPL-03: the authority's answer for a customer document, when the country pack requires the block. */
    private function fiscal(string $type, Company $company, Document $document): ?array
    {
        $authority = $this->rules->authorityFor($type, $company->country);

        if ($authority === null) {
            return null;
        }

        $status = $document->number === null ? null : $this->queue->statusFor(AccountingFiscalSource::KEY, AccountingFiscalSource::typeOf($document->kind), $document->id);
        $answer = (array) ($status['authority'] ?? []);
        $text = fn (mixed $value) => is_scalar($value) && (string) $value !== '' ? (string) $value : null;

        return [
            'authority' => $authority,
            'status' => $status === null ? ($this->queue->transmits($company->id) ? 'pending' : 'off') : $status['status'],
            'invoice_number' => $status === null || $status['invoice_number'] === null ? null : (string) $status['invoice_number'],
            'receipt_number' => $text($answer['receipt_number'] ?? null),
            'receipt_signature' => $text($answer['receipt_signature'] ?? null),
            'internal_data' => $text($answer['internal_data'] ?? null),
            'control_unit_id' => $text($answer['control_unit_id'] ?? null),
            'authority_time' => $text($answer['authority_time'] ?? null),
            'qr' => $text($answer['qr'] ?? null),
        ];
    }

    private function taxLines($lines, string $currency): array
    {
        $sums = [];

        foreach ($lines as $line) {
            if ($line->tax_code_id === null) {
                continue;
            }

            $key = $line->tax_code_id.'|'.($line->tax_rate === null ? '' : self::decimal((string) $line->tax_rate));
            $sums[$key] = ($sums[$key] ?? 0) + (int) $line->tax_minor;
        }

        $names = TaxCode::query()->whereIn('id', array_unique(array_map(fn ($k) => strstr($k, '|', true), array_keys($sums))))->pluck('name', 'id');
        $out = [];

        foreach ($sums as $key => $tax) {
            [$codeId, $rate] = explode('|', $key, 2);
            $out[] = ['name' => (string) ($names[$codeId] ?? ''), 'rate' => $rate === '' ? null : $rate, 'tax' => $this->money($tax, $currency)];
        }

        return $out;
    }

    private function money(int|string $minor, string $currency): array
    {
        return ['minor' => (string) $minor, 'currency' => $currency];
    }

    private function email(?Party $party): ?string
    {
        $email = $party?->emails[0]['address'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    public static function decimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
