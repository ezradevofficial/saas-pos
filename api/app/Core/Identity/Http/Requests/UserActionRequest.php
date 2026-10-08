<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Http\Requests\ActionRequest;

/** AUTH-13: deactivate, reactivate or sign out a user everywhere. No body; the controller checks the policy in scope. */
class UserActionRequest extends ActionRequest {}
