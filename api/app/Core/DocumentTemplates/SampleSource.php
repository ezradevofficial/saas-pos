<?php

namespace App\Core\DocumentTemplates;

/**
 * TPL-01: the data source of a document type whose module is not built
 * yet (invoices, quotes, purchase orders, delivery notes, payslips,
 * statements, letters): its merge fields and line columns, and sample
 * data for the designer. The module replaces it with a real source when
 * it arrives (DataSources::register).
 */
final class SampleSource implements DataSource
{
    /** type => [document fields, line columns]. */
    public const CATALOGUE = [
        'sales.invoice' => [
            ['document.due_date' => 'date', 'document.reference' => 'text'],
            ['item_name' => 'text', 'item_code' => 'text', 'qty' => 'qty', 'unit' => 'text', 'unit_price' => 'money', 'discount' => 'money', 'tax_rate' => 'rate', 'tax' => 'money', 'total' => 'money'],
        ],
        'sales.quote' => [
            ['document.valid_until' => 'date', 'document.reference' => 'text'],
            ['item_name' => 'text', 'item_code' => 'text', 'qty' => 'qty', 'unit' => 'text', 'unit_price' => 'money', 'discount' => 'money', 'tax_rate' => 'rate', 'tax' => 'money', 'total' => 'money'],
        ],
        'procurement.po' => [
            ['document.supplier' => 'text', 'document.due_date' => 'date', 'document.reference' => 'text'],
            ['item_name' => 'text', 'item_code' => 'text', 'qty' => 'qty', 'unit' => 'text', 'unit_price' => 'money', 'tax' => 'money', 'total' => 'money'],
        ],
        'stores.delivery_note' => [
            ['document.reference' => 'text'],
            ['item_name' => 'text', 'item_code' => 'text', 'qty' => 'qty', 'unit' => 'text'],
        ],
        'payroll.payslip' => [
            ['document.employee' => 'text', 'document.period' => 'text'],
            ['description' => 'text', 'amount' => 'money'],
        ],
        'party.statement' => [
            ['document.period' => 'text'],
            ['date' => 'date', 'description' => 'text', 'debit' => 'money', 'credit' => 'money', 'balance' => 'money'],
        ],
        'letter' => [
            ['document.subject' => 'text', 'document.reference' => 'text'],
            ['description' => 'text'],
        ],
    ];

    public function __construct(private readonly string $type) {}

    public function type(): string
    {
        return $this->type;
    }

    public function documentFields(): array
    {
        return self::CATALOGUE[$this->type][0];
    }

    public function lineColumns(): array
    {
        return self::CATALOGUE[$this->type][1];
    }

    public function live(): bool
    {
        return false;
    }

    public function sample(?string $country): array
    {
        $base = Samples::base($this->type, $country, app(FiscalRules::class)->authorityFor($this->type, $country));
        $currency = array_key_first($base['currencies']);
        $scale = $base['scale'];
        unset($base['scale']);
        $m = fn (int $major) => Samples::money($major, $currency, $scale);

        $document = ['number' => 'SAMPLE-0001', 'date' => '2026-10-09', 'currency' => $currency, 'reference' => 'REF-0001',
            'due_date' => '2026-11-08', 'valid_until' => '2026-11-08', 'period' => '2026-10', 'subject' => __('templates.sample.subject'),
            'employee' => __('templates.sample.employee'), 'supplier' => __('templates.sample.supplier')];

        $lines = match ($this->type) {
            'payroll.payslip' => [
                ['description' => __('templates.sample.basic_pay'), 'amount' => $m(50000)],
                ['description' => __('templates.sample.deduction'), 'amount' => $m(-2000)],
            ],
            'party.statement' => [
                ['date' => '2026-10-01', 'description' => __('templates.sample.opening'), 'debit' => null, 'credit' => null, 'balance' => $m(1000)],
                ['date' => '2026-10-03', 'description' => __('templates.sample.invoice').' SAMPLE-0001', 'debit' => $m(2300), 'credit' => null, 'balance' => $m(3300)],
                ['date' => '2026-10-05', 'description' => __('templates.sample.payment_received'), 'debit' => null, 'credit' => $m(1500), 'balance' => $m(1800)],
            ],
            'letter' => [],
            default => [
                ['item_name' => __('templates.sample.item_1'), 'item_code' => 'SKU-001', 'qty' => '2', 'unit' => 'EA', 'unit_price' => $m(500), 'discount' => $m(0), 'tax_rate' => null, 'tax' => $m(138), 'total' => $m(1000)],
                ['item_name' => __('templates.sample.item_2'), 'item_code' => 'SKU-002', 'qty' => '1', 'unit' => 'EA', 'unit_price' => $m(1300), 'discount' => $m(0), 'tax_rate' => null, 'tax' => $m(179), 'total' => $m(1300)],
            ],
        };

        $totals = match ($this->type) {
            'payroll.payslip' => ['subtotal' => $m(50000), 'discount' => $m(0), 'tax' => $m(0), 'total' => $m(48000), 'dual' => null, 'tax_lines' => []],
            'party.statement' => ['subtotal' => $m(1800), 'discount' => $m(0), 'tax' => $m(0), 'total' => $m(1800), 'dual' => null, 'tax_lines' => []],
            'letter', 'stores.delivery_note' => null,
            default => ['subtotal' => $m(2300), 'discount' => $m(0), 'tax' => $m(317), 'total' => $m(2300), 'dual' => null,
                'tax_lines' => [['name' => __('templates.sample.tax'), 'rate' => null, 'tax' => $m(317)]]],
        };

        return [...$base, 'document' => $document, 'lines' => $lines, 'totals' => $totals, 'payments' => [], 'change' => null];
    }
}
