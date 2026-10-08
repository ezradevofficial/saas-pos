<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Http\Requests\ActionRequest;

/** TEN-05: issue a pairing code, suspend, resume or unpair a device. No body; the controller checks the policy in scope. */
class DeviceActionRequest extends ActionRequest {}
