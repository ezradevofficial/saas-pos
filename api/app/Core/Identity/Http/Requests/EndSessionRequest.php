<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Http\Requests\ActionRequest;

/** AUTH-09: end one of the signed-in user's own sessions. No body; the controller checks the policy in scope. */
class EndSessionRequest extends ActionRequest {}
