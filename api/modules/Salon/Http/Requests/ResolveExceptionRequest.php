<?php

namespace Modules\Salon\Http\Requests;

/** POST salon/exceptions/{id}/resolve: `salon.exception.resolve` at the exception's place. */
class ResolveExceptionRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }
}
