<?php

namespace App\Core\Layouts\Http\Controllers;

use App\Core\Layouts\Forms\FormCatalogue;
use App\Core\Layouts\Forms\FormDefinition;
use App\Core\Layouts\Http\Requests\FormLayoutCatalogueRequest;
use App\Core\Layouts\Kinds\FormLayout;
use Illuminate\Http\JsonResponse;

/**
 * LAY-03: what the form layout designer works with: the forms (item,
 * party and the tenant's custom forms) and, for one form, its fields
 * (built-in and custom, with whether each may be hidden) and the layout
 * it has when none is published.
 */
class FormLayoutCatalogueController
{
    public function __invoke(FormLayoutCatalogueRequest $request, FormCatalogue $catalogue): JsonResponse
    {
        $key = $request->validated('form');

        if ($key !== null) {
            return new JsonResponse(['data' => FormLayout::describe($catalogue->find($key))]);
        }

        return new JsonResponse(['data' => array_values(array_map(
            fn (FormDefinition $form) => ['key' => $form->key, 'label' => $form->text($form->label)],
            $catalogue->all(),
        ))]);
    }
}
