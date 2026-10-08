<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Http\Requests\ActionRequest;

/** RBAC-04, RBAC-10: remove a role assignment. No body; the controller checks the policy in scope. */
class RemoveAssignmentRequest extends ActionRequest {}
