<?php

namespace App\Core\Api\Http\Requests;

/** API-03: requests per day, `?days` 7 to 90 (default `api.usage_days`). */
class ApiKeyUsageRequest extends ApiKeyRequest
{
    public function rules(): array
    {
        return [
            'days' => ['sometimes', 'integer', 'between:7,90'],
        ];
    }

    public function days(): int
    {
        return (int) $this->validated('days', (int) config('api.usage_days', 30));
    }
}
