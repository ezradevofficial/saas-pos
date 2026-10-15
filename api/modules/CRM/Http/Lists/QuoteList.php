<?php

namespace Modules\CRM\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Http\Resources\QuoteResource;

/** CRM-17, CRM-32: the quotes list and its export. */
class QuoteList extends CrmList
{
    public function name(): string
    {
        return 'crm-quotes';
    }

    public function auditAction(): string
    {
        return 'crm.quote.export';
    }

    public function title(array $filters): string
    {
        return __('crm.lists.quotes');
    }

    public function resource(Model $model): JsonResource
    {
        return QuoteResource::make($model);
    }

    public function sorts(): array
    {
        return [...parent::sorts(), 'number' => ListSort::column('number'), 'quote_date' => ListSort::column('quote_date')];
    }

    public function columns(): array
    {
        $total = fn (string $key) => ListColumn::make($key, 'crm.columns.'.$key, ['totals', 'currency'],
            fn (array $row, $model, ExportValues $values) => $values->money(['amount_minor' => $row['totals'][$key.'_minor'] ?? null, 'currency' => $row['currency']]));

        return [
            self::text('number'),
            self::named('party'),
            self::status(),
            self::date('quote_date'),
            self::date('valid_until'),
            $total('discount'),
            $total('tax'),
            $total('total'),
            self::named('owner'),
            self::named('branch'),
            self::at('created_at'),
        ];
    }

    public function exportRelations(): array
    {
        return QuoteResource::RELATIONS;
    }
}
