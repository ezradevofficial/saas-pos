<?php

namespace App\Core\Sync\Http\Requests;

/** AUTH-06, AUTH-08: POST sync/device-secret, the device replaces its own secret. */
class RotateDeviceSecretRequest extends DeviceRequest {}
