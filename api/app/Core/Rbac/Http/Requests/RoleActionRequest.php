<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Http\Requests\ActionRequest;

/** RBAC-02: show or archive a role. No body; the controller checks the policy in scope. */
class RoleActionRequest extends ActionRequest {}
