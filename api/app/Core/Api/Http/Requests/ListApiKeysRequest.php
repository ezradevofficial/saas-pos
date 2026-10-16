<?php

namespace App\Core\Api\Http\Requests;

/** API-02: `?status=active|expired|revoked|all` (default all) and `?per_page` (50, at most 200). */
class ListApiKeysRequest extends ApiKeyRequest
{
    public const PER_PAGE = 50;

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,expired,revoked,all'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::PER_PAGE);
    }
}
