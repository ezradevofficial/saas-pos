<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SUB-05: the subscription's status for the banner every signed-in user
 * sees (trial days left, past due, read-only). No amounts or plan.
 */
class SubscriptionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        return [];
    }
}
