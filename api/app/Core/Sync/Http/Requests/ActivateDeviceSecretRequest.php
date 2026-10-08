<?php

namespace App\Core\Sync\Http\Requests;

/** AUTH-06, AUTH-08: POST sync/device-secret/activate {kid, proof}: proof of the pending secret (DeviceSecrets). */
class ActivateDeviceSecretRequest extends DeviceRequest
{
    public function rules(): array
    {
        return ['kid' => ['required', 'string', 'max:32'], 'proof' => ['required', 'string', 'max:64']];
    }
}
