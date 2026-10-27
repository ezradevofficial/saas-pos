<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST hotel/folios/{hotel_folio}/charges (HOT-21): `hotel.folio.charge` at the folio's branch.
 * `{item_id, qty?, unit_price_minor, description?, reason?}` charges a core item (its tax category sets the tax; the screen pre-fills its list price),
 * or `{amount_minor, description}` posts a fee or allowance without an item (untaxed). Posted on the property's business date and audited.
 */
class ChargeFolioRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.folio.charge';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_folio')?->branch_id;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required_without:amount_minor', 'nullable', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')],
            'qty' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
            'unit_price_minor' => ['required_with:item_id', 'nullable', 'integer', 'min:1', 'max:999999999999999'],
            'amount_minor' => ['required_without:item_id', 'nullable', 'integer', 'min:1', 'max:999999999999999'],
            'description' => ['required_without:item_id', 'nullable', 'string', 'max:240'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
