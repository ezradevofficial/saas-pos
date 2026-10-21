<?php

namespace App\Core\Api;

use App\Core\Api\Models\ApiKey;

/**
 * The API key the current request authenticated with (API-02), or none.
 * Request-scoped: ResetTenantContext clears it before every request, and
 * the key guard sets it. Error rendering, the `public-api` middleware and
 * the audit metadata read it.
 */
class ApiKeyContext
{
    private ?ApiKey $key = null;

    public function set(?ApiKey $key): void
    {
        $this->key = $key;
    }

    public function key(): ?ApiKey
    {
        return $this->key;
    }

    public function active(): bool
    {
        return $this->key !== null;
    }

    public function reset(): void
    {
        $this->key = null;
    }
}
