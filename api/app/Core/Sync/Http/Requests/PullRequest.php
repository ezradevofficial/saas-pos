<?php

namespace App\Core\Sync\Http\Requests;

use App\Core\Sync\SyncSources;
use Illuminate\Validation\Rule;

/**
 * NFR-04: GET sync/pull?entities[]=items&entities[]=staff&cursors[items]=...&limit=500.
 * Without `entities`, every entity available to the tenant. A missing or
 * empty cursor starts the entity from the beginning.
 */
class PullRequest extends DeviceRequest
{
    public function rules(): array
    {
        $keys = array_keys(app(SyncSources::class)->available());

        return [
            'entities' => ['sometimes', 'array', 'max:50'],
            'entities.*' => ['string', 'distinct', Rule::in($keys)],
            'cursors' => ['sometimes', 'array', 'max:50'],
            'cursors.*' => ['nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('sync.max_page_size', 1000)],
        ];
    }

    /** @return list<string> */
    public function entities(): array
    {
        return $this->validated('entities') ?? array_keys(app(SyncSources::class)->available());
    }

    /** @return array<string, string> */
    public function cursors(): array
    {
        return array_filter((array) ($this->validated('cursors') ?? []), fn ($cursor, $key) => is_string($key) && is_string($cursor), ARRAY_FILTER_USE_BOTH);
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? config('sync.page_size', 500));
    }
}
