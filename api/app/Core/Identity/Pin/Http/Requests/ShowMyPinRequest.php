<?php

namespace App\Core\Identity\Pin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** AUTH-06: GET me/pos-pin, whether the user has a PIN and card (never the values). */
class ShowMyPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }
}
