<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Http\Requests\ActionRequest;

/** AUTH-05: revoke a pending invitation. No body; the controller checks the policy in scope. */
class RevokeInvitationRequest extends ActionRequest {}
