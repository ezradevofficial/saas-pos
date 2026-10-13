<?php

namespace App\Core\DataExport\Http\Requests;

/**
 * EXP-02: requesting a full export (Owners only, TenantExportRequest)
 * confirms the Owner's password, checked by PasswordCheck.
 */
class StoreTenantExportRequest extends TenantExportRequest
{
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:255']];
    }
}
