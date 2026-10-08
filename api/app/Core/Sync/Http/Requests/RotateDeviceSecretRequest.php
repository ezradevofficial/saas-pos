<?php

namespace App\Core\Sync\Http\Requests;

/** AUTH-06, AUTH-08: POST sync/device-secret/rotate {kid, nonce, proof}: proof of the current secret over a challenge (DeviceSecrets). */
class RotateDeviceSecretRequest extends DeviceRequest
{
    public function rules(): array
    {
        return ['kid' => ['required', 'string', 'max:32'], 'nonce' => ['required', 'string', 'max:64'], 'proof' => ['required', 'string', 'max:64']];
    }
}
