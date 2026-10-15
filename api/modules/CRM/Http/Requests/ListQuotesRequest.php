<?php

namespace Modules\CRM\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Modules\CRM\Http\Lists\QuoteList;
use Modules\CRM\Models\Quote;

/** GET crm/quotes (CRM-17): `crm.quote.view`; the list contract of ListsCrm; `?search=` on the number and terms. */
class ListQuotesRequest extends FormRequest
{
    use ListsCrm;

    public function list(): ListDefinition
    {
        return new QuoteList;
    }

    protected function resource(): string
    {
        return 'quote';
    }

    protected function statuses(): array
    {
        return Quote::STATUSES;
    }

    protected function archivable(): bool
    {
        return false;
    }

    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('number'), 'ilike', $like)->orWhere($query->qualifyColumn('terms'), 'ilike', $like);
    }
}
