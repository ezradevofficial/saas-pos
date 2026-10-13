<?php

namespace App\Core\DataExport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EXP-02: a full export's zip behind a temporary signed URL (`signed`
 * middleware) bound to the Owner who requested it. The signature is the
 * credential; the controller enters the signed tenant and checks the
 * signed-for user is still that export's requester and an active Owner.
 */
class TenantExportDownloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
