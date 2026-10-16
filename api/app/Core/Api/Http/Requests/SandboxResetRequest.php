<?php

namespace App\Core\Api\Http\Requests;

/** API-05: reset a sandbox's data (no body); the developer permission `core.api_key.manage` at tenant scope. */
class SandboxResetRequest extends ApiKeyRequest {}
