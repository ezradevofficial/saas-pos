<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Http\Requests\ActionRequest;

/** AUTH-09: sign out of the current session. No body. */
class SignOutRequest extends ActionRequest {}
