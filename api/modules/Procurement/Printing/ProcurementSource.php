<?php

namespace Modules\Procurement\Printing;

use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\DocumentTemplates\Samples;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Order;

/**
 * TPL-01, PRC-12, PRC-13: the data sources of `procurement.po` (approved
 * POs), `procurement.award_letter` and `procurement.regret_letter` (opened
 * bids of awarded tenders, by bid id), with sample data for the designer.
 */
final class ProcurementSource implements RecordSource
{
    public const PO = 'procurement.po';

    public const AWARD = 'procurement.award_letter';

    public const REGRET = 'procurement.regret_letter';

    public const LINE_COLUMNS = ['item_name' => 'text', 'item_code' => 'text', 'qty' => 'qty', 'unit' => 'text', 'unit_price' => 'money', 'tax' => 'money', 'total' => 'money'];

    public function __construct(private readonly string $type) {}

    public function type(): string
    {
        return $this->type;
    }

    public function documentFields(): array
    {
        return match ($this->type) {
            // Stage 7B walkthrough: Procurement's own fields, never the POS refund's `document.reference` ("Original receipt").
            self::PO => ['document.supplier' => 'text', 'document.expected_on' => 'date', 'document.requisition_ref' => 'text', 'document.delivery_to' => 'text', 'document.terms' => 'text'],
            self::AWARD => ['document.subject' => 'text', 'document.tender_ref' => 'text', 'document.bidder' => 'text', 'document.tender_title' => 'text', 'document.awarded_total' => 'money'],
            default => ['document.subject' => 'text', 'document.tender_ref' => 'text', 'document.bidder' => 'text', 'document.tender_title' => 'text'],
        };
    }

    public function lineColumns(): array
    {
        return $this->type === self::REGRET ? ['description' => 'text'] : self::LINE_COLUMNS;
    }

    public function live(): bool
    {
        return true;
    }

    public function load(string $recordId): ?DocumentData
    {
        $prints = app(ProcurementPrints::class);

        if ($this->type === self::PO) {
            $order = Order::query()->whereIn('status', [Order::APPROVED, Order::PARTIALLY_RECEIVED, Order::RECEIVED, Order::CLOSED])->find($recordId);

            return $order === null ? null : $prints->order($order);
        }

        $bid = Bid::query()->where('status', Bid::OPENED)->find($recordId);

        if ($bid === null || ($this->type === self::AWARD) !== ($bid->outcome === Bid::AWARDED) || $bid->outcome === null) {
            return null;
        }

        return $prints->letter($bid);
    }

    public function sample(?string $country): array
    {
        $base = Samples::base($this->type, $country, null);
        $currency = array_key_first($base['currencies']);
        $scale = $base['scale'];
        unset($base['scale']);
        $m = fn (int $major) => Samples::money($major, $currency, $scale);
        $lines = $this->type === self::REGRET ? [] : [
            ['item_name' => __('templates.sample.item_1'), 'item_code' => 'SKU-001', 'qty' => '2', 'unit' => 'EA', 'unit_price' => $m(500), 'tax' => $m(0), 'total' => $m(1000), 'custom' => []],
            ['item_name' => __('templates.sample.item_2'), 'item_code' => 'SKU-002', 'qty' => '1', 'unit' => 'EA', 'unit_price' => $m(1300), 'tax' => $m(0), 'total' => $m(1300), 'custom' => []],
        ];

        return [
            ...$base,
            'fiscal' => null,
            'document' => [
                'number' => $this->type === self::PO ? 'PO-BR1-2026-00001' : 'TND-BR1-2026-00001',
                'date' => '2026-10-09',
                'currency' => $currency,
                'supplier' => __('templates.sample.supplier'),
                ...($this->type === self::PO ? ['expected_on' => '2026-10-30', 'requisition_ref' => 'PR-BR1-2026-00003'] : ['tender_ref' => 'TND-BR1-2026-00001']),
                'delivery_to' => __('templates.sample.location'),
                'terms' => null,
                'subject' => __('procurement.letters.'.($this->type === self::AWARD ? 'award' : 'regret').'_subject', ['title' => __('procurement.letters.sample_title')]),
                'bidder' => __('templates.sample.supplier'),
                'tender_title' => __('procurement.letters.sample_title'),
                'awarded_total' => $m(2300),
            ],
            'lines' => $lines,
            'totals' => $this->type === self::REGRET ? null : ['subtotal' => $m(2300), 'discount' => $m(0), 'tax' => $m(0), 'total' => $m(2300), 'dual' => null, 'tax_lines' => []],
            'payments' => [],
            'change' => null,
        ];
    }
}
