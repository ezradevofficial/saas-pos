<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An action on one record with no body (archive, restore, suspend, revoke
 * ...). Every endpoint goes through a Form Request; for these there is
 * nothing to validate, and the controller authorises through the record's
 * policy and ScopeResolver (404 out of scope, 403 in scope but not allowed),
 * so authorize() lets the request through to it.
 */
abstract class ActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }
}
