<?php

namespace Modules\Stores\Prints;

use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\DocumentTemplates\Samples;
use App\Core\Identity\Models\User;
use Modules\Stores\Http\Resources\Values;
use Modules\Stores\Models\Issue;

/**
 * TPL-01, STR-14: the data sources of `stores.delivery_note` and
 * `stores.issue_voucher` (real issues, IssuePrints), with sample data for
 * the designer. Values print only for a reader who may see them (RBAC-05).
 */
final class StoresDocumentSource implements RecordSource
{
    public function __construct(private readonly string $type) {}

    public function type(): string
    {
        return $this->type;
    }

    public function documentFields(): array
    {
        return $this->type === IssuePrints::ISSUE_VOUCHER
            ? [...IssuePrints::DOCUMENT_FIELDS, 'document.total_value' => 'money']
            : IssuePrints::DOCUMENT_FIELDS;
    }

    public function lineColumns(): array
    {
        return $this->type === IssuePrints::ISSUE_VOUCHER ? IssuePrints::VOUCHER_COLUMNS : IssuePrints::COLUMNS;
    }

    public function live(): bool
    {
        return true;
    }

    public function load(string $recordId): ?DocumentData
    {
        $issue = Issue::query()->find($recordId);

        if ($issue === null) {
            return null;
        }

        $user = request()->user();

        return app(IssuePrints::class)->document($issue, $this->type, $user instanceof User && Values::visible(request(), $issue->location_id));
    }

    public function sample(?string $country): array
    {
        $base = Samples::base($this->type, $country, null);
        $currency = array_key_first($base['currencies']);
        $scale = $base['scale'];
        unset($base['scale']);
        $m = fn (int $major) => Samples::money($major, $currency, $scale);
        $voucher = $this->type === IssuePrints::ISSUE_VOUCHER;

        $lines = [
            ['item_name' => __('templates.sample.item_1'), 'item_code' => 'SKU-001', 'qty' => '4', 'unit' => 'EA', 'custom' => []],
            ['item_name' => __('templates.sample.item_2'), 'item_code' => 'SKU-002', 'qty' => '1', 'unit' => 'BOX', 'custom' => []],
        ];

        if ($voucher) {
            $lines[0] += ['unit_cost' => $m(25), 'value' => $m(100)];
            $lines[1] += ['unit_cost' => $m(300), 'value' => $m(300)];
        }

        return [
            ...$base,
            'customer' => null,
            'fiscal' => null,
            'document' => [
                'number' => 'ISS-BR1-2026-00001', 'date' => '2026-10-09 10:15', 'currency' => $currency, 'requisition' => 'REQ-BR1-2026-00001',
                'store' => __('templates.sample.location'), 'department' => __('stores.sample.department'), 'cost_centre' => __('stores.sample.cost_centre'),
                'issued_by' => __('stores.sample.issued_by'), 'received_by' => __('stores.sample.received_by'),
                ...($voucher ? ['total_value' => $m(400)] : []),
            ],
            'lines' => $lines,
            'totals' => null,
            'payments' => [],
            'change' => null,
        ];
    }
}
