<?php

namespace Modules\Accounting\Documents;

use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\FiscalRules;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\DocumentTemplates\Samples;
use Modules\Accounting\Models\Document;

/**
 * TPL-01, ACC-19: the data sources of `accounting.invoice`,
 * `accounting.credit_note` (real documents, DocumentPrints) and
 * `accounting.statement` (printed from the statement report; no stored
 * record to load), with sample data for the designer.
 */
final class AccountingDocumentSource implements RecordSource
{
    public function __construct(private readonly string $type) {}

    public function type(): string
    {
        return $this->type;
    }

    public function documentFields(): array
    {
        return $this->type === 'accounting.statement'
            ? ['document.from' => 'date', 'document.to' => 'date', 'document.opening' => 'money', 'document.closing' => 'money']
            : ['document.due_date' => 'date', 'document.reference' => 'text', 'document.memo' => 'text'];
    }

    public function lineColumns(): array
    {
        return $this->type === 'accounting.statement'
            ? ['date' => 'date', 'number' => 'text', 'type' => 'text', 'due_date' => 'date', 'amount' => 'money', 'balance' => 'money']
            : DocumentPrints::COLUMNS;
    }

    public function live(): bool
    {
        return true;
    }

    public function load(string $recordId): ?DocumentData
    {
        if ($this->type === 'accounting.statement') {
            return null;
        }

        $document = Document::query()->where('status', Document::POSTED)->find($recordId);

        return $document === null || DocumentPrints::TYPES[$document->kind] !== $this->type ? null : app(DocumentPrints::class)->document($document);
    }

    public function sample(?string $country): array
    {
        $base = Samples::base($this->type, $country, app(FiscalRules::class)->authorityFor($this->type, $country));
        $currency = array_key_first($base['currencies']);
        $scale = $base['scale'];
        unset($base['scale']);
        $m = fn (int $major) => Samples::money($major, $currency, $scale);

        if ($this->type === 'accounting.statement') {
            return [
                ...$base,
                'fiscal' => null,
                'document' => ['number' => '', 'date' => '2026-10-31', 'currency' => $currency, 'from' => '2026-10-01', 'to' => '2026-10-31', 'opening' => $m(500), 'closing' => $m(1300)],
                'lines' => [
                    ['date' => '2026-10-05', 'number' => 'INV-2026-00012', 'type' => __('accounting.statement_types.customer_invoice'), 'due_date' => '2026-11-04', 'amount' => $m(1200), 'balance' => $m(1700), 'custom' => []],
                    ['date' => '2026-10-20', 'number' => 'RCT-2026-00031', 'type' => __('accounting.statement_types.receipt'), 'due_date' => null, 'amount' => $m(-400), 'balance' => $m(1300), 'custom' => []],
                ],
                'totals' => ['subtotal' => $m(500), 'discount' => $m(0), 'tax' => $m(0), 'total' => $m(1300), 'dual' => null, 'tax_lines' => []],
                'payments' => [],
                'change' => null,
            ];
        }

        return [
            ...$base,
            'document' => ['number' => $this->type === 'accounting.credit_note' ? 'CN-2026-00001' : 'INV-2026-00001', 'date' => '2026-10-09', 'due_date' => '2026-11-08',
                'currency' => $currency, 'reference' => $this->type === 'accounting.credit_note' ? 'INV-2026-00001' : null, 'memo' => null],
            'lines' => [
                ['description' => __('templates.sample.item_1'), 'item_code' => 'SKU-001', 'qty' => '2', 'unit_price' => $m(250), 'tax_rate' => null, 'tax' => $m(0), 'net' => $m(500), 'total' => $m(500), 'custom' => []],
                ['description' => __('templates.sample.item_2'), 'item_code' => null, 'qty' => '1', 'unit_price' => $m(120), 'tax_rate' => null, 'tax' => $m(0), 'net' => $m(120), 'total' => $m(120), 'custom' => []],
            ],
            'totals' => ['subtotal' => $m(620), 'discount' => $m(0), 'tax' => $m(0), 'total' => $m(620), 'dual' => null, 'tax_lines' => []],
            'payments' => [],
            'change' => null,
        ];
    }
}
