<?php

namespace App\Core\Sync\Http\Requests;

/** AUTH-06, AUTH-08: GET sync/device-secret/challenge, a one-time nonce for a rotation. */
class DeviceSecretChallengeRequest extends DeviceRequest
{
    public function rules(): array
    {
        return [];
    }
}
