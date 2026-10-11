<?php

namespace App\Core\Layouts\Http\Requests;

use App\Core\Layouts\Forms\FormCatalogue;
use App\Core\Layouts\Forms\FormDefinition;
use App\Core\Layouts\LayoutsServiceProvider;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LAY-03: GET form-layouts: the forms whose layouts may be designed, or
 * (`?form=`) one form's fields and default layout. The list is for
 * holders of any layout permission (core.layout.view|edit|publish), one
 * form's fields for holders of core.layout.edit. Custom form types appear
 * only for users who may use them, and fields their field rules hide are
 * left out (RBAC-05).
 */
class FormLayoutCatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);

        // LAY-03: one form's fields are for those who design layouts.
        if ($this->has('form')) {
            return $resolver->can($this->user(), LayoutsServiceProvider::PERMISSIONS['edit']);
        }

        foreach (LayoutsServiceProvider::PERMISSIONS as $permission) {
            if ($resolver->can($this->user(), $permission)) {
                return true;
            }
        }

        return false;
    }

    public function rules(): array
    {
        return ['form' => ['sometimes', 'string', Rule::in(array_keys($this->forms()))]];
    }

    /**
     * The forms the user sees in the designer (a custom form type only when
     * they may use it; CF-04, RBAC-04).
     *
     * @return array<string, FormDefinition>
     */
    public function forms(): array
    {
        return array_filter(app(FormCatalogue::class)->all(), fn (FormDefinition $form) => $form->visibleTo($this->user()));
    }
}
