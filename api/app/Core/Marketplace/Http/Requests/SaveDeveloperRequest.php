<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Marketplace\Developers;

/**
 * MKT-01: register as a developer (the terms, with the 80/20 revenue
 * share, must be accepted once) or update the public name and contact.
 */
class SaveDeveloperRequest extends DeveloperRequest
{
    public function rules(): array
    {
        $registered = app(Developers::class)->current() !== null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255', 'url:https'],
            'accept_terms' => $registered ? ['sometimes', 'boolean'] : ['required', 'accepted'],
        ];
    }
}
