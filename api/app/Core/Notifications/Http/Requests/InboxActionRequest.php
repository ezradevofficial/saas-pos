<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Http\Requests\ActionRequest;

/**
 * NOT-01: mark read, mark all read, archive: on the signed-in user's own
 * notifications only (the controller answers 404 for anyone else's).
 */
class InboxActionRequest extends ActionRequest {}
