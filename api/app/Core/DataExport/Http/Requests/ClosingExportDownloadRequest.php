<?php

namespace App\Core\DataExport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * NFR-08, EXP-02: a closed tenant's export behind the signed link emailed to
 * its Owners. No sign-in: the signature is the credential, and the
 * controller checks it and its expiry (403, 410), enters the signed tenant
 * and serves the closing export only while the tenant is closed and the
 * signed-for user is still one of its active Owners.
 */
class ClosingExportDownloadRequest extends FormRequest
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
