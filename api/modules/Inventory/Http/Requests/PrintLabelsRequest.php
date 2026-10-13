<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST inventory/labels (INV-21): `inventory.label.print`;
 * `{labels: [{item_id, uom_id?, batch_id?, copies}], price_list_id?,
 * format: html|pdf}`. At most 500 labels in one print.
 */
class PrintLabelsRequest extends FormRequest
{
    public const MAX_LABELS = 500;

    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'inventory.label.print');
    }

    public function rules(): array
    {
        return [
            'labels' => ['required', 'array', 'min:1', 'max:100'],
            'labels.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')],
            'labels.*.uom_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('uoms', 'id')],
            'labels.*.batch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('inventory_batches', 'id')],
            'labels.*.copies' => ['required', 'integer', 'between:1,'.self::MAX_LABELS],
            'price_list_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('price_lists', 'id')->whereNull('archived_at')],
            'format' => ['sometimes', 'string', 'in:html,pdf'],
            'language' => ['sometimes', 'string', 'in:en,fr'],
        ];
    }
}
