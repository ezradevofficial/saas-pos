<?php

namespace App\Core\Approvals\Http\Requests;

use App\Core\Approvals\ApprovalActions;

/**
 * APR-03: POST approvals/{approval}/attachments (multipart `file`): a
 * PDF, image, text, CSV, Word or Excel file of at most 10 MB.
 */
class AttachToApprovalRequest extends ApprovalItemRequest
{
    public const MAX_KB = 10240;

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimetypes:'.implode(',', array_keys(ApprovalActions::MIMES))],
        ];
    }

    public function attributes(): array
    {
        return ['file' => __('approvals.attributes.file')];
    }
}
