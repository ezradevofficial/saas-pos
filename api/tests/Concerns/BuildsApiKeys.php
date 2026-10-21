<?php

namespace Tests\Concerns;

use App\Core\Identity\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * API keys made through the API (API-02, ADR 020), for a tenant built
 * with BuildsOrganisation. By default: the Admin template at tenant scope
 * with the core read and write scopes the tests use.
 */
trait BuildsApiKeys
{
    use BuildsOrganisation;

    public const DEFAULT_SCOPES = ['core.item.write', 'core.party.write', 'core.company.read', 'core.branch.read', 'core.location.read', 'core.uom.read', 'core.item_category.read'];

    protected function createKey(array $data = [], ?User $as = null): TestResponse
    {
        return $this->postJson('/api/v1/api-keys', array_merge([
            'name' => 'Integration',
            'role_id' => $this->roles->get('admin')->id,
            'scope_type' => 'tenant',
            'scopes' => self::DEFAULT_SCOPES,
        ], $data), $this->headersFor($as));
    }

    /** @return array{id: string, secret: string} a new key's id and its plain secret (shown once) */
    protected function apiKey(array $data = [], ?User $as = null): array
    {
        $response = $this->createKey($data, $as)->assertCreated();

        return ['id' => $response->json('data.id'), 'secret' => $response->json('secret')];
    }

    /** Bearer headers for a plain key. */
    protected function keyHeaders(string $secret, array $headers = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json'], $headers);
    }
}
