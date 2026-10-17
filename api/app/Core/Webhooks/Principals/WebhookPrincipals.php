<?php

namespace App\Core\Webhooks\Principals;

use App\Core\Identity\Models\User;
use App\Core\Webhooks\Models\WebhookSubscription;
use Closure;

/**
 * HOOK-01: resolves a subscription's principal by type. Core registers
 * `user`; the public API registers `api_key` (a closure taking the key id
 * and answering its WebhookPrincipal, or null when it no longer exists).
 * Resolved principals are memoised per id for the job or request.
 */
class WebhookPrincipals
{
    /** @var array<string, Closure(string): ?WebhookPrincipal> */
    private array $resolvers = [];

    /** @var array<string, ?WebhookPrincipal> */
    private array $memo = [];

    public function __construct()
    {
        $this->register(UserPrincipal::TYPE, function (string $id): ?WebhookPrincipal {
            $user = User::query()->find($id);

            return $user === null ? null : new UserPrincipal($user);
        });
    }

    /** @param  Closure(string): ?WebhookPrincipal  $resolver */
    public function register(string $type, Closure $resolver): void
    {
        $this->resolvers[$type] = $resolver;
        $this->memo = [];
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->resolvers);
    }

    public function resolve(string $type, string $id): ?WebhookPrincipal
    {
        $key = $type.':'.$id;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $resolver = $this->resolvers[$type] ?? null;

        return $this->memo[$key] = $resolver === null ? null : $resolver($id);
    }

    public function of(WebhookSubscription $subscription): ?WebhookPrincipal
    {
        return $this->resolve($subscription->principal_type, $subscription->principal_id);
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
