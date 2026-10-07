<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Services\Invitations;
use App\Core\Identity\Services\PasswordPolicy;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST auth/invitations/{token}/accept (AUTH-05): public. The token must
 * name an open invitation (404, 410); the password follows the inviting
 * tenant's policy (AUTH-02).
 */
class AcceptInvitationRequest extends FormRequest
{
    private ?Invitation $invitation = null;

    public function authorize(): bool
    {
        $this->invitation = app(Invitations::class)->open((string) $this->route('token'));

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => PasswordPolicy::rules(Tenant::find($this->invitation()->tenant_id)),
        ];
    }

    public function invitation(): Invitation
    {
        return $this->invitation;
    }
}
