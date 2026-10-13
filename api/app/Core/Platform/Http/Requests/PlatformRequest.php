<?php

namespace App\Core\Platform\Http\Requests;

use App\Core\Platform\Models\PlatformUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request from signed-in platform staff. `ability` names what the
 * staff member's role must allow (PlatformUser::allows); null lets any
 * staff member through (their own account, sign-out, enrolment).
 */
abstract class PlatformRequest extends FormRequest
{
    protected ?string $ability = null;

    public function authorize(): bool
    {
        $user = $this->staff();

        return $user !== null && ($this->ability === null || $user->allows($this->ability));
    }

    public function rules(): array
    {
        return [];
    }

    public function staff(): ?PlatformUser
    {
        $user = $this->user('platform');

        return $user instanceof PlatformUser ? $user : null;
    }
}
