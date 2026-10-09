<?php

namespace App\Core\Layouts\Http\Requests;

use App\Core\Layouts\Forms\FormCatalogue;
use App\Core\Layouts\LayoutsServiceProvider;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LAY-03: GET form-layouts: the forms whose layouts may be designed, or
 * (`?form=`) one form's fields and default layout, for holders of any
 * layout permission (core.layout.view|edit|publish).
 */
class FormLayoutCatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);

        foreach (LayoutsServiceProvider::PERMISSIONS as $permission) {
            if ($resolver->can($this->user(), $permission)) {
                return true;
            }
        }

        return false;
    }

    public function rules(): array
    {
        return ['form' => ['sometimes', 'string', Rule::in(app(FormCatalogue::class)->keys())]];
    }
}
