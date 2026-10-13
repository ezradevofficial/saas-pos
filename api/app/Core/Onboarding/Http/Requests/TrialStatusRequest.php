<?php

namespace App\Core\Onboarding\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ONB-04: the trial's state and days left are always visible, so every
 * signed-in user of the tenant may read them (nothing else is answered).
 */
class TrialStatusRequest extends FormRequest
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
