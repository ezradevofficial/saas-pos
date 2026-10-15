<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Manufacturing\Orders\Revalue;
use Modules\Manufacturing\Support\Places;

/**
 * POST manufacturing/revalue (MFG-15, MFG-20, review fix 5): `{kind:
 * issue|return|report|backflush, document_id}`; the document must be the
 * tenant's (422 `document_unknown` otherwise); `manufacturing.settings.edit`
 * at the document's company (403).
 */
class RevalueRequest extends FormRequest
{
    private ?Model $document = null;

    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'manufacturing.settings.edit');
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(Revalue::KINDS)],
            'document_id' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->document = Revalue::find((string) $this->input('kind'), (string) $this->input('document_id'));

                if ($this->document === null) {
                    $validator->errors()->add('document_id', __('manufacturing.errors.document_unknown'));

                    return;
                }

                if (! app(Places::class)->atCompany($this->user(), 'manufacturing.settings.edit', (string) $this->document->company_id)) {
                    throw new AuthorizationException(__('rbac.forbidden'));
                }
            },
        ];
    }

    public function document(): Model
    {
        return $this->document;
    }
}
