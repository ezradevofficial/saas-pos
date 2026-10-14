<?php

namespace Modules\Stores\Prints;

use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\IssueLine;
use Modules\Stores\Models\Requisition;
use Modules\Stores\Stock\Qty;

/**
 * STR-14, TPL-01: an issue's data for its document templates, in the shape
 * every template reads (DataSource): `stores.delivery_note` (what was
 * handed over, no values) and `stores.issue_voucher` (the store's record,
 * with unit cost and value when valued and the reader may see them,
 * RBAC-05). Business names as entered; times in the company's time zone.
 */
class IssuePrints
{
    public const DELIVERY_NOTE = 'stores.delivery_note';

    public const ISSUE_VOUCHER = 'stores.issue_voucher';

    /** `?document=` => document type. */
    public const TYPES = ['delivery_note' => self::DELIVERY_NOTE, 'issue_voucher' => self::ISSUE_VOUCHER];

    public const DOCUMENT_FIELDS = [
        'document.requisition' => 'text', 'document.store' => 'text', 'document.department' => 'text', 'document.cost_centre' => 'text',
        'document.issued_by' => 'text', 'document.received_by' => 'text',
    ];

    public const COLUMNS = ['item_name' => 'text', 'item_code' => 'text', 'qty' => 'qty', 'unit' => 'text'];

    public const VOUCHER_COLUMNS = [...self::COLUMNS, 'unit_cost' => 'money', 'value' => 'money'];

    public function __construct(private readonly CurrencyDecimals $decimals) {}

    public function document(Issue $issue, string $type, bool $values): DocumentData
    {
        $company = Company::query()->findOrFail($issue->company_id);
        $branch = Branch::query()->find($issue->branch_id);
        $location = Location::query()->find($issue->location_id);
        $lines = IssueLine::query()->where('issue_id', $issue->id)->orderBy('line_no')->get();
        $items = Item::query()->whereKey($lines->pluck('item_id')->unique())->get()->keyBy('id');
        $uoms = Uom::query()->whereKey($lines->pluck('uom_id')->unique())->get()->keyBy('id');
        $voucher = $type === self::ISSUE_VOUCHER;
        $currency = $issue->currency ?? $company->base_currency;
        $valued = $voucher && $values && $issue->valued_at !== null;
        $zone = $company->timezone ?: 'UTC';
        $total = BigInteger::zero();

        $rows = $lines->map(function (IssueLine $line) use ($items, $uoms, $voucher, $valued, $currency, &$total) {
            $item = $items->get($line->item_id);
            $row = [
                'item_name' => $item?->name,
                'item_code' => $item?->code,
                'qty' => Qty::show($line->qty),
                'unit' => $uoms->get($line->uom_id)?->code,
                'custom' => [],
            ];

            if ($voucher) {
                $value = $valued && $line->value_minor !== null ? BigInteger::of((string) $line->value_minor) : null;
                $total = $value === null ? $total : $total->plus($value);
                $row['unit_cost'] = $value === null ? null : $this->money((string) BigDecimal::of($value)->dividedBy((string) $line->qty, 0, RoundingMode::HalfUp), $currency);
                $row['value'] = $value === null ? null : $this->money((string) $value, $currency);
            }

            return $row;
        })->values()->all();

        $data = [
            'currencies' => [$currency => $this->decimals->for($currency)],
            'company' => [
                'name' => $company->name, 'legal_name' => $company->legal_name ?: $company->name, 'tax_id' => $company->tax_id,
                'address' => is_string($company->address) ? $company->address : null, 'phone' => null, 'email' => null,
                'country' => $company->country, 'logo' => null, 'custom' => [],
            ],
            'branch' => $branch === null ? null : ['name' => $branch->name, 'code' => $branch->code, 'address' => null],
            'location' => $location === null ? null : ['name' => $location->name, 'code' => $location->code],
            'document' => [
                'number' => $issue->number,
                'date' => $issue->issued_at->setTimezone($zone)->format('Y-m-d H:i'),
                'currency' => $currency,
                'requisition' => (string) Requisition::query()->whereKey($issue->requisition_id)->value('number'),
                'store' => $location?->name,
                'department' => Department::query()->whereKey($issue->department_id)->value('name'),
                'cost_centre' => $issue->cost_centre_id === null ? null : CostCentre::query()->whereKey($issue->cost_centre_id)->value('name'),
                'issued_by' => $issue->issuer?->name,
                'received_by' => $issue->acknowledger?->name,
                ...($voucher ? ['total_value' => $valued ? $this->money((string) $total, $currency) : null] : []),
            ],
            'customer' => null,
            'lines' => $rows,
            'totals' => null,
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];

        return new DocumentData($type, $issue->id, $data, $company->id, $branch?->id, $company->country);
    }

    private function money(string $minor, string $currency): array
    {
        return ['minor' => $minor, 'currency' => $currency];
    }
}
