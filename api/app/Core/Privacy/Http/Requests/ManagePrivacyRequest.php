<?php

namespace App\Core\Privacy\Http\Requests;

/** NFR-08: carry out an approved request, or download an access report. */
class ManagePrivacyRequest extends PrivacyRequestAction
{
    protected string $permission = 'core.privacy_request.manage';
}
