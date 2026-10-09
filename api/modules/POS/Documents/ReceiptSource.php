<?php

namespace Modules\POS\Documents;

use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\FiscalRules;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\DocumentTemplates\Samples;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;

/**
 * TPL-01, POS-06: the data source of the POS receipt (`pos.receipt`) and
 * the refund receipt (`pos.refund_receipt`): real sales and refunds
 * (ReceiptData), and sample data for the designer.
 */
final class ReceiptSource implements RecordSource
{
    public const COLUMNS = [
        'item_name' => 'text', 'item_code' => 'text', 'qty' => 'qty', 'unit' => 'text', 'unit_price' => 'money',
        'discount' => 'money', 'tax_rate' => 'rate', 'tax' => 'money', 'total' => 'money',
    ];

    public function __construct(private readonly string $type) {}

    public function type(): string
    {
        return $this->type;
    }

    public function documentFields(): array
    {
        return $this->type === 'pos.refund_receipt'
            ? ['document.cashier' => 'text', 'document.reference' => 'text', 'document.reason' => 'text']
            : ['document.cashier' => 'text'];
    }

    public function lineColumns(): array
    {
        return self::COLUMNS;
    }

    public function live(): bool
    {
        return true;
    }

    public function load(string $recordId): ?DocumentData
    {
        $data = app(ReceiptData::class);

        if ($this->type === 'pos.refund_receipt') {
            $refund = Refund::query()->find($recordId);

            return $refund === null ? null : $data->refund($refund);
        }

        $sale = Sale::query()->find($recordId);

        return $sale === null ? null : $data->sale($sale);
    }

    public function sample(?string $country): array
    {
        $base = Samples::base($this->type, $country, app(FiscalRules::class)->authorityFor($this->type, $country));
        $currency = array_key_first($base['currencies']);
        $scale = $base['scale'];
        unset($base['scale']);
        $m = fn (int $major) => Samples::money($major, $currency, $scale);
        $refund = $this->type === 'pos.refund_receipt';

        return [
            ...$base,
            'document' => [
                'number' => $refund ? 'RF-L01-000001' : 'R-L01-000001',
                'date' => '2026-10-09 14:32',
                'cashier' => __('templates.sample.cashier'),
                'currency' => $currency,
                'reference' => $refund ? 'R-L01-000001' : null,
                'reason' => $refund ? __('templates.sample.reason') : null,
            ],
            'lines' => [
                ['item_name' => __('templates.sample.item_1'), 'item_code' => 'SKU-001', 'qty' => '2', 'unit' => 'EA', 'unit_price' => $m(250), 'discount' => $m(0), 'tax_rate' => null, 'tax' => $m(69), 'total' => $m(500), 'custom' => []],
                ['item_name' => __('templates.sample.item_2'), 'item_code' => 'SKU-002', 'qty' => '1', 'unit' => 'EA', 'unit_price' => $m(120), 'discount' => $m(20), 'tax_rate' => null, 'tax' => $m(14), 'total' => $m(100), 'custom' => []],
            ],
            'totals' => [
                'subtotal' => $m(620), 'discount' => $m(20), 'tax' => $m(83), 'total' => $m(600), 'dual' => null,
                'tax_lines' => [['name' => __('templates.sample.tax'), 'rate' => null, 'tax' => $m(83)]],
            ],
            'payments' => [['method' => __('templates.sample.payment'), 'amount' => $m(1000), 'reference' => null]],
            'change' => $refund ? null : $m(400),
        ];
    }
}
