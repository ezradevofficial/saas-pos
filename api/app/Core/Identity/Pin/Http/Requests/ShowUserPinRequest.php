<?php

namespace App\Core\Identity\Pin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AUTH-06: GET users/{user}/pos-pin, whether a user has a POS PIN and card
 * and must change the PIN (never the values), for whoever may view the
 * user (RBAC-04; out of scope is not found).
 */
class ShowUserPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->user()->can('view', $this->route('user')), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
