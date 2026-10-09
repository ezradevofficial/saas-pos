<?php

namespace Modules\POS\Http\Requests;

use App\Core\DocumentTemplates\Models\DocumentShare;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TPL-04: a sale's receipt in the back office.
 *
 * - GET pos/sales/{pos_sale}/receipt?format=html|pdf: print or download,
 *   for a viewer of the sale (`pos.sale.view` at its location).
 * - POST .../email {email?, language?}, POST .../share, GET .../shares,
 *   POST .../shares/{document_share}/revoke: `pos.sale.share` too.
 *
 * A sale out of the user's scope is not found (404), so ids are never
 * confirmed; a viewer without the share permission gets 403. A share
 * must be one of this sale's.
 */
class SaleDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sale = $this->route('pos_sale');
        abort_unless($sale !== null && $this->user()->can('pos.sale.view', $sale), 404);

        $share = $this->route('document_share');

        if ($share instanceof DocumentShare) {
            abort_unless($share->document_type === 'pos.receipt' && $share->record_id === $sale->id, 404);
        }

        return $this->isMethod('GET') && $this->route()->getActionMethod() === 'receipt'
            ? true
            : $this->user()->can('pos.sale.share', $sale);
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'receipt' => ['format' => ['sometimes', 'string', Rule::in(['html', 'pdf'])]],
            'email' => [
                'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254'],
                'language' => ['sometimes', 'string', Rule::in(['en', 'fr'])],
            ],
            default => [],
        };
    }

    public function attributes(): array
    {
        return [
            'email' => __('templates.attributes.email'),
            'language' => __('templates.attributes.language'),
            'format' => __('templates.attributes.format'),
        ];
    }
}
