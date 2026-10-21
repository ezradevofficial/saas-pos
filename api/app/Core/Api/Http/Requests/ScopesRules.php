<?php

namespace App\Core\Api\Http\Requests;

use App\Core\Api\ApiScopes;
use Illuminate\Validation\Rule;

/** API-02: a key's scopes, from the catalogue (`module.resource.read|write`). */
trait ScopesRules
{
    /** @return array<string, list<mixed>> */
    protected function scopesRules(bool $required): array
    {
        return [
            'scopes' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:500'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(app(ApiScopes::class)->catalogue())],
        ];
    }
}
