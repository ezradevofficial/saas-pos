<?php

namespace Modules\Salon\Http\Requests;

/** PUT salon/resources/{id}: `salon.resource.manage` at the resource's branch. */
class UpdateResourceRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'kind' => ['sometimes', 'required', 'string', 'max:30'],
        ];
    }
}
