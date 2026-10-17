<?php

namespace App\Core\Privacy\Http\Requests;

/** NFR-08: approve a request (never your own, PrivacyRequests). */
class ApprovePrivacyRequest extends PrivacyRequestAction
{
    protected string $permission = 'core.privacy_request.approve';
}
