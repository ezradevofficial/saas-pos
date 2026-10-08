<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Http\Requests\ActionRequest;

/** TEN-06: archive or restore a company, branch or location. No body; the controller checks the policy in scope. */
class ArchiveRequest extends ActionRequest {}
