<?php

namespace App\Core\Identity\Pin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AUTH-06: PUT and DELETE users/{user}/pos-pin, an administrator resets or
 * removes another user's PIN. Needs `core.user.edit` covering every scope
 * of the user (UserPolicy::update); a user out of scope is not found.
 * The administrator always confirms with their own password (owner ruling
 * 2026-10-09). The PIN is never returned.
 */
class UserPinRequest extends FormRequest
{
    use ValidatesNewPin;

    public function authorize(): bool
    {
        $target = $this->route('user');
        abort_unless($this->user()->can('view', $target), 404);

        return $this->user()->can('update', $target);
    }

    public function rules(): array
    {
        // The administrator's own password, for themselves or anyone else.
        return ['password' => ['required', 'string', 'max:255'], ...($this->isMethod('DELETE') ? [] : $this->newPinRules())];
    }
}
