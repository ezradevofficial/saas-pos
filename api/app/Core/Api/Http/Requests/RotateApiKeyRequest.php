<?php

namespace App\Core\Api\Http\Requests;

/** API-02: a rotation, with the hours the previous secret keeps working (0 to `api.keys.max_overlap_hours`). */
class RotateApiKeyRequest extends ApiKeyRequest
{
    public function rules(): array
    {
        return [
            'overlap_hours' => ['sometimes', 'integer', 'between:0,'.(int) config('api.keys.max_overlap_hours', 168)],
        ];
    }

    public function overlapHours(): int
    {
        return (int) $this->validated('overlap_hours', (int) config('api.keys.default_overlap_hours', 24));
    }
}
