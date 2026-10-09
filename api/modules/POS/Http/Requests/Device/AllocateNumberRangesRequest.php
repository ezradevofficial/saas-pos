<?php

namespace Modules\POS\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Modules\POS\Sync\NumberRanges;

/**
 * POST pos/number-ranges: the device's active ranges of a document type,
 * topped up when few numbers remain (NUM-02). `next` is the next number the
 * device will use, so the server knows what is spent.
 */
class AllocateNumberRangesRequest extends FormRequest
{
    use UploadRules;

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'in:'.implode(',', NumberRanges::TYPES)],
            'next' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
